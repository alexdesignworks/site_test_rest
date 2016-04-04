<?php
/**
 * @file
 * Site Test REST functionality and helpers.
 */

/**
 * Trait SiteRestTestCase.
 */
trait SiteRestTestCase {
  /**
   * Storage object for responses.
   *
   * @var object
   */
  protected $responseStorage;

  /**
   * Setup REST test.
   *
   * Call this from setUp() method of your test class.
   */
  protected function restSetUp() {
    // Create a file name based on current test id.
    $suffix = implode('_', [$this->testId, time(), rand(pow(10, 2), pow(10, 3))]);
    $filename = 'temporary://site_test_rest_response_' . $suffix . '.json';
    // Set filename to a variable to pass it to the client in SUT.
    variable_set('site_test_rest_response_file', $filename);
    $this->refreshVariablesRunner();
    $this->responseStorage = new SiteTestRestObjectStorage($filename);
  }

  /**
   * Tear down REST test.
   *
   * Call this from tearDown() method of your test class.
   */
  protected function restTearDown() {
    // Delete storage.
    unset($this->responseStorage);
    // Unset response file variable.
    variable_del('site_test_rest_response_file');
    $this->refreshVariablesRunner();
  }

  /**
   * Set response for specific request.
   *
   * @param object|array $request
   *   Request object or array.
   * @param object|array $response
   *   Response object or array to set.
   */
  protected function setResponse($request, $response) {
    $request = (object) $request;
    $response = (object) $response;
    $response->criteria = $request;

    $this->responseStorage->add($response);
  }

  /**
   * Refresh the in-memory set of variables for a test runner.
   *
   * Refresh the in-memory set of variables. Useful after a page request is made
   * that changes a variable in a different thread.
   *
   * This is an overridden version of the function provided by the Drupal test
   * module. It maintains any settings created in settings.php (and it's
   * corresponding global.inc) file.
   *
   * In other words calling a settings page with $this->drupalPost() with a
   * changed value would update a variable to reflect that change, but in
   * the thread that made the call (thread running the test) the changed
   * variable would not be picked up.
   *
   * This method clears the variables cache and loads a fresh copy from
   * the database to ensure that the most up-to-date set of variables is loaded.
   */
  protected function refreshVariablesRunner() {
    global $conf;
    cache_clear_all('variables', 'cache_bootstrap');
    $variables = variable_initialize();
    // Merge updated database variables back into $conf.
    foreach ($variables as $name => $value) {
      $conf[$name] = $value;
    }

    return $conf;
  }

}

/**
 * Class SiteTestRestHttpMockClient.
 *
 * HTTP Client class mock. Use this class as a DI service for your class that
 * uses HTTP client.
 *
 * In your module,
 *
 * @code
 * // Allow other modules to override HTTP client class.
 * $http_client_class = variable_get('YOURMODULE_http_client_class',
 *   'YourModuleHttpClient');
 * $http_client = new $http_client_class();
 * // Inject HTTP Client as a service.
 * $client = new YourClassThatUsesHttpClient($http_client);
 * // Perform HTTP request and get response.
 * $response = $client->request($request);
 * // And then in your test,
 * // Replace HTTP Client class with a mocked client and refresh variables
 * // to make SUT see the variables changed.
 * variable_set('YOURMODULE_http_client_class', 'SiteTestRestHttpMockClient');
 * $this->refreshVariablesRunner();
 * @endcode
 */
class SiteTestRestHttpMockClient {
  /**
   * Storage to manage responses.
   *
   * @var SiteTestRestObjectStorage
   */
  protected $responseStorage;

  /**
   * Constructor.
   *
   * @param array $config
   *   Optional array of client configuration.
   */
  public function __construct($config = []) {
    foreach ($config as $k => $v) {
      $this->{$k} = $v;
    }

    // Retrieve a file name from the variable storage and pass it to the
    // storage for initialisation. It is very important that a test should
    // refresh variables in order to make this value 'visible' to current
    // system under test (SUT).
    $filename = variable_get('site_test_rest_response_file');
    $this->responseStorage = new SiteTestRestObjectStorage($filename);
  }

  /**
   * Perform HTTP request.
   *
   * @param object $request
   *   Request object with 'url' and 'method' properties set.
   *
   * @return object
   *   Response object.
   */
  public function request($request) {
    // Search for request in storage.
    $search_criteria = (object) [
      'url' => $request->url,
      'method' => $request->method,
    ];

    $response = $this->responseStorage->search($search_criteria);

    if (!$response) {
      $response = (object) [
        'code' => 404,
        'data' => t('Mocked test response was not found in storage for URL !url and method @method. Please make sure that setResponse() has correct path set, including URL query parameters.', [
          '!url' => $request->url,
          '@method' => $request->method,
        ]),
      ];
    }
    unset($response->criteria);

    return $response;
  }

}

/**
 * Class SiteTestRestObjectStorage.
 */
class SiteTestRestObjectStorage {
  protected $filename;

  /**
   * Constructor.
   *
   * @param string $filename
   *   Optional file name to store data in.
   */
  public function __construct($filename = '') {
    if ($filename) {
      $this->filename = $filename;
    }
    else {
      $suffix = implode('_', [time(), rand(pow(10, 2), pow(10, 3))]);
      $this->filename = 'temporary://site_test_rest_response_' . $suffix . '.json';
    }
    $this->initFile();
  }

  /**
   * Destructor.
   */
  public function __destruct() {
    // Delete storage file, but only if it is called from within a test.
    // It can also be called by any other outside processes that result in
    // error.
    if (is_file($this->filename) && count(debug_backtrace(FALSE)) > 2) {
      unlink($this->filename);
    }
  }

  /**
   * Get storage file name.
   *
   * @return string
   *   Storage file name.
   */
  public function getFilename() {
    return $this->filename;
  }

  /**
   * Set storage file name.
   *
   * @param string $filename
   *   Storage file name.
   */
  public function setFilename($filename) {
    $this->filename = $filename;
  }

  /**
   * Initialise storage file.
   *
   * @param bool $reset
   *   Flag to force file contents reset.
   */
  protected function initFile($reset = FALSE) {
    if (!file_exists($this->filename) || $reset) {
      file_put_contents($this->filename, '');
    }
  }

  /**
   * Add an object to storage.
   *
   * @param object $object
   *   Object to store.
   *
   * @return bool|int
   *   Number of bytes that were written to the file, or FALSE on failure.
   */
  public function add($object) {
    $contents = $this->getAll();
    $contents[] = $object;

    return file_put_contents($this->filename, $this->export($contents));
  }

  /**
   * Reset storage.
   */
  public function reset() {
    $this->initFile(TRUE);
  }

  /**
   * Search for object among other stored objects.
   *
   * @param object $criteria
   *   Object with specified properties to match to.
   *
   * @return object
   *   Stored object if all properties of $search_object matched stored object,
   *   FALSE otherwise.
   */
  public function search($criteria) {
    $criteria_properties = array_keys(get_object_vars($criteria));

    $objects = $this->getAll();
    $match = FALSE;
    foreach ($objects as $object) {
      foreach ($criteria_properties as $criteria_property) {
        if (property_exists($object->criteria, $criteria_property) && $this->criteriaMatches($object->criteria->{$criteria_property}, $criteria->{$criteria_property})) {
          $match = TRUE;
        }
        else {
          $match = FALSE;
          break;
        }
      }
      if ($match) {
        return $object;
      }
    }

    return FALSE;
  }

  /**
   * Get all stored objects.
   *
   * @return array
   *   Array of previously stored objects or
   *   empty array if there was a problem while retrieving objects.
   */
  public function getAll() {
    $objects = NULL;
    if (is_readable($this->filename)) {
      $contents = file_get_contents($this->filename);
      $objects = $this->import($contents);
    }

    return !$objects || !is_array($objects) ? [] : $objects;
  }

  /**
   * Export object for writing.
   */
  protected function export($object) {
    return json_encode($object, JSON_PRETTY_PRINT);
  }

  /**
   * Import object after reading.
   */
  protected function import($data) {
    return json_decode($data);
  }

  /**
   * Check whether object value matches criteria.
   *
   * @param mixed $object_value
   *   Object value to check for a criteria match. If string, can be a valid
   *   regular expression or a string value.
   * @param mixed $criteria_value
   *   Criteria value to match to.
   *
   * @return bool
   *   TRUE if value matches criteria, FALSE otherwise.
   */
  protected function criteriaMatches($object_value, $criteria_value) {
    if ($this->isRegex($object_value)) {
      return (bool) preg_match($object_value, $criteria_value);
    }

    return $object_value === $criteria_value;
  }

  /**
   * Check that provided value is a regex.
   */
  protected function isRegex($value) {
    if (!is_string($value)) {
      return FALSE;
    }

    return (bool) preg_match('/^\/.+\/[a-z]*$/i', $value);
  }

}
