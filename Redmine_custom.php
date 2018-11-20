<?php if (!defined('ROOTPATH')) exit('No direct script access allowed'); ?>
<?php
// session_start();


class Redmine_custom_defect_plugin extends Defect_plugin
{
	private $_api;
	
	private $_address;
	private $_user;
	private $_password;

	private $_is_legacy = false;
	private $_trackers;
	private $_categories;
	
	private static $_meta = array(
		'author' => 'Gurock Software',
		'version' => '1.0',
		'description' => 'Redmine defect plugin for TestRail',
		'can_push' => true,
		'can_lookup' => true,
		'default_config' => 
			'; Please configure your Redmine connection below
; For Redmine versions older than 1.3, you need to
; activate the legacy mode of this plugin. Please
; contact the Gurock Software support in case you
; have any questions or refer to the documentation:
; http://on.gurock.com/redmine35
[connection]
address=http://<your-server>/
user=testrail
password=secret'
	);
	
	public function get_meta()
	{
		return self::$_meta;
	}
	
	// *********************************************************
	// CONFIGURATION
	// *********************************************************
	
	public function validate_config($config)
	{
		$ini = ini::parse($config);
		
		if (!isset($ini['connection']))
		{
			throw new ValidationException('Missing [connection] group');
		}
		
		$keys = array('address', 'user', 'password');
		
		// Check required values for existance
		foreach ($keys as $key)
		{
			if (!isset($ini['connection'][$key]) ||
				!$ini['connection'][$key])
			{
				throw new ValidationException(
					"Missing configuration for key '$key'"
				);
			}
		}
		
		$address = $ini['connection']['address'];
		
		// Check whether the address is a valid url (syntax only)
		if (!check::url($address))
		{
			throw new ValidationException('Address is not a valid url');
		}

		if (isset($ini['connection']['mode']))
		{
			// Mode must be set to 'legacy' when available.
			if ($ini['connection']['mode'] != 'legacy')
			{
				throw new ValidationException(
					'Mode given but not set to "legacy"'
				);
			}

			if (!isset($ini['trackers']))
			{
				throw new ValidationException(
					'Using legacy mode but [trackers] is missing'
				);
			}
		}
	}
	
	public function configure($config)
	{
		$ini = ini::parse($config);
		$this->_address = str::slash($ini['connection']['address']);
		$this->_user = $ini['connection']['user'];
		$this->_password = $ini['connection']['password'];

		if (isset($ini['connection']['mode']))
		{
			$this->_is_legacy = true;
			$this->_trackers = $ini['trackers'];
		}
	}
	
	private function _parse_categories($ini)
	{
		$categories = array();

		// Uses the given ini section with keys 'project_id.item_id'
		// to create a category key => value mapping for the given
		// projects.
		foreach ($ini as $key => $value)
		{
			if (preg_match('/^([^\.]+)\.([^\.]+)$/', $key, $matches))
			{
				$project_id = (int) $matches[1];
				$item_id = (int) $matches[2];
				$categories[$project_id][$item_id] = $value;
			}
		}

		return $categories;
	}

	// *********************************************************
	// API / CONNECTION
	// *********************************************************
	
	private function _get_api()
	{
		if ($this->_api)
		{
			return $this->_api;
		}
		
		$this->_api = new Redmine_api(
			$this->_address,
			$this->_user,
			$this->_password);
		
		return $this->_api;
	}
	
	// *********************************************************
	// PUSH
	// *********************************************************

	public function prepare_push($context)
	{
		// Return a form with the following fields/properties
		return array(
			'fields' => array(
				'subject' => array(
					'type' => 'string',
					'label' => 'Subject',
					'required' => true,
					'size' => 'full'
				),
				'tracker' => array(
					'type' => 'dropdown',
					'label' => 'Tracker',
					'required' => true,
					'remember' => true,
					'size' => 'compact'
				),
				'project' => array(
					'type' => 'dropdown',
					'label' => 'Project',
					'required' => true,
					'remember' => true,
					'cascading' => true,
					'size' => 'compact'
				),
                'error_type' => array(
                    'type' => 'dropdown',
                    'label' => 'Тип ошибки',
                    'required' => true,
                    'remember' => true,
                    'cascading' => true,
                    'size' => 'compact'
                ),
				'parent_issue_id' => array(
					'type' => 'string',
					'label' => 'Родительская задача',
                    'required' => true,
					'remember' => true,
					'size' => 'compact'
				),
				'description' => array(
					'type' => 'text',
					'label' => 'Description',
					'rows' => 10
				)
			)
		);
	}
	
	private function _get_subject_default($context)
	{		
		$test = current($context['tests']);
		$subject = '[TR] Ошибка в кейсе: ' . $test->case->title;
		
		if ($context['test_count'] > 1)
		{
			$subject .= ' (+others)';
		}
		return $subject;
	}
	

	private function _get_description_default($context)
	{
		return $context['test_change']->description;
	}

	
	private function _to_id_name_lookup($items)
	{
		$result = array();
		foreach ($items as $item)
		{
			$result[$item->id] = $item->name;
		}
		return $result;
	}

	private function _get_trackers($api)
	{
		// In legacy mode for Redmine versions older than 1.3, we use
		// the user-configured values for the trackers. Otherwise,
		// we can just use the API.
		if ($this->_is_legacy)
		{
			if (is_array($this->_trackers))
			{						
				return $this->_trackers;
			}
			else 
			{
				return null;
			}
		}
		else 
		{
			return $this->_to_id_name_lookup(
				$api->get_trackers()
			);
		}
	}

	private function _get_categories($api, $project_id)
	{
		// In legacy mode for Redmine versions older than 1.3, we use
		// the user-configured values for the categories. Otherwise,
		// we can just use the API.
		if ($this->_is_legacy)
		{
			$categories = arr::get($this->_categories, $project_id);

			if (!is_array($categories))
			{
				return null;
			}

			return $categories;
		}
		else
		{
			return $this->_to_id_name_lookup(
				$api->get_categories($project_id)
			);
		}
	}
	
	public function prepare_field($context, $input, $field)
	{
		$data = array();
        
		
		// Process those fields that do not need a connection to the
		// Redmine installation.		
		if ($field == 'subject' || $field == 'description')
		{
			switch ($field)
			{
				case 'subject':
					$data['default'] = $this->_get_subject_default(
						$context);
					break;
					
				case 'description':
					$data['default'] = $this->_get_description_default(
						$context);
					break;
			}
		
			return $data;
		}
		
		// Take into account the preferences of the user, but only
		// for the initial form rendering (not for dynamic loads).
		if ($context['event'] == 'prepare')
		{
			$prefs = arr::get($context, 'preferences');
		}
		else
		{
			$prefs = null;
		}
		
		// And then try to connect/login (in case we haven't set up a
		// working connection previously in this request) and process
		// the remaining fields.
		$api = $this->_get_api();

        // var_dump($data);
		
		switch ($field)
		{
			case 'tracker':
				$data['default'] = arr::get($prefs, 'tracker');
				$data['options'] = $this->_get_trackers($api);
				break;

			case 'project':
				$data['default'] = arr::get($prefs, 'project');
				$data['options'] = $this->_to_id_name_lookup(
					$api->get_projects()
				);
				break;

            case 'parent_issue_id':
                $data['default'] = arr::get($prefs, 'parent_issue_id');
                break;

            case 'error_type':
                $data['default'] = "Внутренняя ошибка";
                $data['options'] = [
                                    "Внутренняя ошибка"=>"Внутренняя ошибка",
                                    "Ошибка от клиента"=>"Ошибка от клиента"
                                    ];
                break;

			// case 'category':
			// 	if (isset($input['project']))
			// 	{
			// 		$data['default'] = arr::get($prefs, 'category');
			// 		$data['options'] = $this->_get_categories($api,
			// 			$input['project']);
			// 	}
			// 	break;
		}
		
		return $data;
	}
	
	public function validate_push($context, $input)
	{
	}

	public function push($context, $input)
	{
		$api = $this->_get_api();
		
		$data = array();
		$data['subject'] = $input['subject'];
		$data['tracker'] = $input['tracker'];
		$data['project'] = $input['project'];
		$data['parent_issue_id'] = $input['parent_issue_id'];
		// $data['category'] = $input['category'];
		$data['description'] = $input['description'];
        $data['custom_fields'] = [["id"=>119,"name"=>"Тип ошибки","value"=> $input['error_type']]];
        // var_dump($data);
			
		
		return $api->add_issue($data);
	}
	
	// *********************************************************
	// LOOKUP
	// *********************************************************
	
	public function lookup($defect_id)
	{
		$api = $this->_get_api();
		$issue = $api->get_issue($defect_id);

		$status_id = GI_DEFECTS_STATUS_OPEN;
		
		if (isset($issue->status))
		{
			$status = $issue->status->name;
			
			// Redmine's status API is only available in Redmine 1.3
			// or later, unfortunately, so we can only try to guess
			// by its name.
			switch (str::to_lower($status))
			{
				case 'resolved':
					$status_id = GI_DEFECTS_STATUS_RESOLVED;
					break;

				case 'closed':
					$status_id = GI_DEFECTS_STATUS_CLOSED;
					break;
			}
		}
		else 
		{
			$status = null;
		}
		
		if (isset($issue->description) && $issue->description)
		{
			$description = str::format(
				'<div class="monospace">{0}</div>',
				nl2br(
					html::link_urls(
						h($issue->description)
					)
				)
			);
		}
		else
		{
			$description = null;
		}
		
		// Add some important attributes for the issue such as the
		// current status and project.
		
		$attributes = array();
		
		if (isset($issue->tracker))
		{
			$attributes['Tracker'] = h($issue->tracker->name);
		}

		if ($status)
		{
			$attributes['Status'] = h($status);
		}

		if (isset($issue->project))
		{
			// Add a link back to the project (issue list).
			$attributes['Project'] = str::format(
				'<a target="_blank" href="{0}projects/{1}">{2}</a>',
				a($this->_address),
				a($issue->project->id),
				h($issue->project->name)
			);
		}

		if (isset($issue->category))
		{
			$attributes['Category'] = h($issue->category->name);
		}
		
		return array(
			'id' => $defect_id,
			'url' => str::format(
				'{0}issues/{1}',
				$this->_address,
				$defect_id
			),
			'title' => $issue->subject,
			'status_id' => $status_id,
			'status' => $status,
			'description' => $description,
			'attributes' => $attributes
		);
	}
}

/**
 * Redmine API
 *
 * Wrapper class for the Redmine API with functions for retrieving
 * projects, bugs etc. from a Redmine installation.
 */
class Redmine_api
{
	private $_address;
	private $_user;
	private $_password;
	private $_version;
	private $_curl;
	
	/**
	 * Construct
	 *
	 * Initializes a new Redmine API object. Expects the web address
	 * of the Redmine installation including http or https prefix.
	 */	
	public function __construct($address, $user, $password)
	{
		$this->_address = str::slash($address);
		$this->_user = $user;
		$this->_password = $password;
	}
	
	private function _throw_error($format, $params = null)
	{
		$args = func_get_args();
		$format = array_shift($args);
		
		if (count($args) > 0)
		{
			$message = str::formatv($format, $args);
		}
		else 
		{
			$message = $format;
		}
		
		throw new RedmineException($message);
	}
	
	private function _send_command($method, $command, $data = array())
	{
		$url = $this->_address . $command . '.json';

		if ($method == 'GET')
		{
			$url .= '?limit=100';
		}

		return $this->_send_request($method, $url, $data);
	}
	
	private function _send_request($method, $url, $data)
	{
		if (!$this->_curl)
		{
			// Initialize the cURL handle. We re-use this handle to
			// make use of Keep-Alive, if possible.
			$this->_curl = http::open();
		}

		$response = http::request_ex(
			$this->_curl,
			$method, 
			$url, 
			array(
				'data' => $data,
				'user' => $this->_user,
				'password' => $this->_password,
				'headers' => array(
					'Content-Type' => 'application/json'
				)
			)
		);

		// In case debug logging is enabled, we append the data
		// we've sent and the entire request/response to the log.
		if (logger::is_on(GI_LOG_LEVEL_DEBUG))
		{
			logger::debugr('$data', $data);
			logger::debugr('$response', $response);
		}
		
		$obj = json::decode($response->content);
		
		if ($response->code != 200)
		{
			if ($response->code != 201) // Created
			{
				$this->_throw_error(
					'Invalid HTTP code ({0}). Please check your user/' .
					'password and that the API is enabled in Redmine.',
					$response->code
				);
			}
		}
		
		return $obj;
	}

	/**
	 * Get Issue
	 *
	 * Gets an existing issue from the Redmine installation and
	 * returns it. The resulting issue object has various properties
	 * such as the subject, description, project etc.
	 */	 
	public function get_issue($issue_id)
	{
		$response = $this->_send_command(
			'GET', 'issues/' . urlencode($issue_id)
		);
		
		return $response->issue;
	}
	
	/**
	 * Get Trackers
	 *
	 * Gets the available trackers for the Redmine installation.
	 * Trackers are returned as array of objects, each with its ID
	 * and name. Requires Redmine >= 1.3.
	 */
	public function get_trackers()
	{
		$response = $this->_send_command('GET', 'trackers');
		return $response->trackers;
	}

	/**
	 * Get Projects
	 *
	 * Gets the available projects for the Redmine installation.
	 * Projects are returned as array of objects, each with its ID
	 * and name.	 
	 */
	public function get_projects()
	{
		$response = $this->_send_command('GET', 'projects');
		return $response->projects;
	}
	
	/**
	 * Get Categories
	 *
	 * Gets the available categories for the given project ID for the
	 * Redmine installation. Categories are returned as array of
	 * objects, each with its ID and name. Requires Redmine >= 1.3.
	 */
	public function get_categories($project_id)
	{
		$response = $this->_send_command('GET', 
			"projects/$project_id/issue_categories");
		return $response->issue_categories;
	}
	
	/**
	 * Add Issue
	 *
	 * Adds a new issue to the Redmine installation with the given
	 * parameters (subject, project etc.) and returns its ID.
	 *
	 * subject:     The title of the new issue
	 * tracker:     The ID of the tracker of the new issue (bug,
	 *              feature request etc.)
	 * project:     The ID of the project the issue should be added
	 *              to
	 * category:    The ID of the category the issue is added to
	 * description: The description of the new issue
	 */	
	public function add_issue($options)
	{
		$issue = obj::create();
		$issue->subject = $options['subject'];
		$issue->description = $options['description'];
		$issue->tracker_id = $options['tracker'];
		$issue->project_id = $options['project'];
        $issue->custom_fields = $options['custom_fields'];
        $issue->parent_issue_id = $options['parent_issue_id'];
		$data = json::encode(array('issue' => $issue));
		$response = $this->_send_command('POST', 'issues', $data);
		return $response->issue->id;
	}
}

class RedmineException extends Exception
{
}
