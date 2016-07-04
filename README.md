# Site REST Test
## Drupal module to mock REST responses in tests

## Example

In `mymodule.module`

```php
/**
 * Centralised REST client getter.
 */
function mymodule_integration_client() {
  $client = &drupal_static(__FUNCTION__);

  if (!$client) {
    // Allow other modules to override HTTP client class.
    // The value of this variable will be replaced in tests' setUp()
    // method allowing to inject our test HTTP Client as dependency.
    $http_client_class = variable_get('MYMODULE_http_client_class', 'MyModuleHttpClient');
    $http_client = new $http_client_class();
    // Inject HTTP Client as a service to the class that is an
    // integration client.
    $client = new MyClassThatUsesHttpClient($http_client);
  }

  return $client;
}

/**
 * Perform some REST request.
 *
 * @param object $request
 *   Request object expected by HTTP client.
 *
 * @return object
 *   Response object.
 */
function mymodule_some_rest_request($request) {
  $client = mymodule_integration_client();

  return $client->request($request);
}
```

In `mymodule.test`

```php
class MyRestTest {
  // Use Site Test trait.
  use SiteRestTestCase;

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return [
      'name' => 'My module',
      'description' => 'Test some feature in my module.',
      'group' => 'My group',
      // Run this test in 'site' mode using site_test module.
      'mode' => 'site',
    ];
  }

  /**
   * Test some feature.
   */
  public function testFeature() {
    // Entities that are expected to be returned in REST response.
    $mocked_entities = [
      (object) [
        'id' => 1,
      ],
      (object) [
        'id' => 2,
      ],
    ];

    $this->setResponse(
      // Expected Request parameters that mymodule_some_rest_request()
      // will perform.
      [
        'method' => 'GET',
        'url' => 'expected/request/url',
      ],
      // Mocked response to be returned.
      [
        'code' => 200,
        'data' => json_encode($mocked_entities),
      ]
    );

    // Now, perform test.
    $response = mymodule_some_rest_request();

    // Assert actual data received.
    // Usually, there is no need for this assertion. Instead, assert
    // that other functionality that is relying on responses is
    // working correctly. 
    $this->assertEqual($response->data, $mocked_entities);
  }
}
```
