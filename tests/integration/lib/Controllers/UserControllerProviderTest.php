<?php

namespace IntegrationTests\Controllers;

use CCR\Json;
use Exception;
use Models\Services\Tokens;
use stdClass;
use TestHarness\TokenHelper;
use TestHarness\Utilities;
use TestHarness\XdmodTestHelper;

class UserControllerProviderTest extends BaseUserAdminTest
{
    /**
     * Tests the UserControllerProvider route: GET /users/current
     *
     * @dataProvider provideGetCurrentUser
     *
     * @param array $options
     * @throws Exception if unable to authenticate as the specified user role.
     */
    public function testGetCurrentUser(array $options)
    {
        $user = $options['user'];
        $expected = $options['expected'];
        $expectedFile = $expected['file'];
        $expectedHttpCode = $expected['http_code'];
        $expectedContentType = $expected['content_type'];


        if ($user !== 'pub') {
            $this->helper->authenticate($user);
        }

        $response = $this->helper->get('rest/v1/users/current');

        $this->validateResponse($response, $expectedHttpCode, $expectedContentType);

        $expected = JSON::loadFile(
            $this->getTestFiles()->getFile('user_controller', $expectedFile)
        );

        $actual = $response[0];

        $this->assertEquals($expected, $actual);

        if ($user !== 'pub') {
            $this->helper->logout();
        }
    }

    /**
     * @dataProvider provideTestAPITokens
     * @param array $options
     * @return void
     * @throws Exception
     */
    public function testAPITokensCRD(array $options)
    {
        $hydratedOptions = $this->hydrateOptions($options, 'create_api_tokens');

        $user = $hydratedOptions->user;
        $expected = $hydratedOptions->expected;

        if ('pub' !== $user) {
            $this->helper->authenticate($user);
        }
        TokenHelper::revokeAPIToken($this, $this->helper);

        // Attempt to get the current API token, this should fail.
        TokenHelper::getAPIToken(
            $this,
            $this->helper,
            $expected->api_get->http_code,
            $expected->api_get->content_type,
            $expected->api_get->schemas->failure
        );

        // Attempt to create an API token.
        TokenHelper::createAPIToken(
            $this,
            $this->helper,
            $expected->api_create->http_code,
            $expected->api_create->content_type,
            $expected->api_create->schemas->success
        );

        // Now test that we can't create a token when we already have a valid token.
        TokenHelper::createAPIToken(
            $this,
            $this->helper,
            $expected->api_create->http_code,
            $expected->api_create->content_type,
            $expected->api_create->schemas->failure
        );

        // Now test if we can get the newly created token, this should succeed.
        TokenHelper::getAPIToken(
            $this,
            $this->helper,
            $expected->api_get->http_code,
            $expected->api_get->content_type,
            $expected->api_get->schemas->success
        );

        // Now we can revoke the token we just created.
        TokenHelper::revokeAPIToken(
            $this,
            $this->helper,
            $expected->api_revoke->http_code,
            $expected->api_revoke->content_type,
            $expected->api_revoke->schemas->success
        );

        // We cannot revoke a token if we don't have one.
        TokenHelper::revokeAPIToken(
            $this,
            $this->helper,
            $expected->api_revoke->http_code,
            $expected->api_revoke->content_type,
            $expected->api_revoke->schemas->failure
        );

        // We still can't get a token if we don't have one.
        TokenHelper::getAPIToken(
            $this,
            $this->helper,
            $expected->api_get->http_code,
            $expected->api_get->content_type,
            $expected->api_get->schemas->failure
        );

        if ('pub' !== $user) {
            $this->helper->logout();
        }
    }

    /**
     * This tests that API Token authentication is working for the controller operation:
     * `html/controllers/metric_explorer.php?operation=get_dw_descripter`.
     *
     * @dataProvider provideTestControllerTokenAuthentication
     * @param array $options
     * @return void
     * @throws Exception
     */
    public function testControllerTokenAuthentication(array $options)
    {
        $this->endpointTokenAuthenticationTest($options, 'test_endpoint_token_auth');
    }

    /**
     * This tests that API Token authentication is working for the Warehouse Export REST Controllers getRealms endpoint:
     * `rest/warehouse/export/realms`.
     *
     * @dataProvider provideTestRestTokenAuthentication
     *
     * @param array $options
     * @return void
     * @throws Exception
     */
    public function testRestTokenAuthentication(array $options)
    {
        $this->endpointTokenAuthenticationTest($options, 'test_rest_token_auth');
    }

    /**
     * A helper function that will test the end point provided with $options for it's ability to support API Token
     * authentication. Currently API Token authentication is supported for both controllers/* and Rest endpoints. This
     * is accomplished by a user logging into XDMoD normally and generating an API Token. Then, when making a request to
     * and endpoint that supports API Token authentication they include this token as a request header
     * 'Bearer: <token_value>`.
     *
     * The steps of this test are as follows:
     *   - Attempt a request to the endpoint located at $options->test->url before authorization. This is expected to fail.
     *   - If we're not testing the public user then log the test user.
     *   - Create a new API token for the currently logged in user.
     *   - Add the newly created token to $this->helpers->headers so that it will be included when making requests.
     *   - Attempt a request to the test endpoint again this time w/ the token in the headers.
     *   - Verify that the results of the API Token authenticated request are as expected.
     *   - Begin cleanup by clearing the API Token from the helpers headers.
     *   - Revoke the previously created API Token.
     *   - and finally, if the test user is not the public user then log them out.
     *
     * @param array $options
     * @param string $testId
     *
     * @return void
     *
     * @throws Exception
     */
    private function endpointTokenAuthenticationTest(array $options, $testId)
    {
        $hydratedOptions = $this->hydrateOptions($options, $testId);

        $user = $hydratedOptions->user;
        $expected = $hydratedOptions->expected;
        $test = $hydratedOptions->test;

        // Attempt to make a request to the controller endpoint unauthenticated in any way.
        // This should fail.
        $this->makeRequest(
            $this->helper,
            $test->url,
            $test->verb,
            $test->parameters,
            $test->data,
            $expected->test->failure->http_code,
            $expected->test->failure->content_type,
            'schema/integration',
            $expected->test->failure->schema,
            ''
        );

        // Now go ahead and authenticate the test user so we can create / use their API Token.
        if ('pub' !== $user) {
            $this->helper->authenticate($user);
        }

        // Attempt to create an API token.
        $tokenResponse = TokenHelper::createAPIToken(
            $this,
            $this->helper,
            $expected->api_create->http_code,
            $expected->api_create->content_type,
            $expected->api_create->schemas->success
        );

        if ('pub' !== $user) {
            $token = $tokenResponse->data->token;
            // We add the token to the request headers so that we can use the token authentication.
            $this->helper->addheader('Authorization', sprintf('%s %s', Tokens::HEADER_KEY, $token));

            // While we prefer the 'Authorization' header approach to token authentication. We also support having it
            // provided as a query parameter. Also, retrieving the 'Authorization' header on el7 isn't working, so
            // this is the only way to test authentication.
            if (!property_exists($test, 'parameters') || !isset($test->parameters)) {
                $test->parameters = array();
            } elseif (is_object($test->parameters)) {
                $test->parameters = (array)$test->parameters;
            }

            $test->parameters[Tokens::HEADER_KEY] = $token;
            $this->makeRequest(
                $this->helper,
                $test->url,
                $test->verb,
                $test->parameters,
                $test->data,
                $expected->test->success->http_code,
                $expected->test->success->content_type,
                $expected->test->success->file_group,
                $expected->test->success->file_name,
                'output'
            );

            // clean up the helper's headers.
            $this->helper->addheader('Authorization', null);
        }

        // Make sure to revoke the token so that we leave the user in the same state as we found it.
        TokenHelper::revokeAPIToken(
            $this,
            $this->helper,
            $expected->api_revoke->http_code,
            $expected->api_revoke->content_type,
            $expected->api_revoke->schemas->success
        );

        // And finally make sure that we log out.
        if ('pub' !== $user) {
            $this->helper->logout();
        }
    }

    /**
     * @param array $options
     * @param string $testId the id of the test calling this function, will be used to retrieve the default test options
     * @return stdClass containing the provided options merged w/ token_auth_defaults and the defaults for $testId.
     * @throws Exception
     */
    protected function hydrateOptions(array $options, $testId)
    {
        $tokenAuthDefaults = Json::loadFile($this->getTestFiles()->getFile('user_controller', 'token_auth_defaults', 'input'), false);
        $testDefaults = Json::loadFile($this->getTestFiles()->getFile('user_controller', sprintf('%s_defaults', $testId), 'input'), false);

        $authedOptions = Utilities::applyDefaults(json_decode(json_encode($options)), $tokenAuthDefaults);
        return Utilities::applyDefaults($authedOptions, $testDefaults);
    }

    /**
     * @return array|object
     * @throws Exception
     */
    public function provideGetCurrentUser()
    {
        return JSON::loadFile(
            $this->getTestFiles()->getFile('user_controller', 'get_current_user-8.0.0', 'input')
        );
    }

    public function provideTestAPITokens()
    {
        return JSON::loadFile(
            $this->getTestFiles()->getFile('user_controller', 'create_api_tokens', 'input')
        );
    }

    public function provideTestControllerTokenAuthentication()
    {
        return JSON::loadFile(
            $this->getTestFiles()->getFile('user_controller', 'test_endpoint_token_auth', 'input')
        );
    }

    public function provideTestRestTokenAuthentication()
    {
        return JSON::loadFile(
            $this->getTestFiles()->getFile('user_controller', 'test_endpoint_token_auth', 'input')
        );
    }
}
