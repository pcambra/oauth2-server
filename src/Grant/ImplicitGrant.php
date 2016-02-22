<?php

namespace League\OAuth2\Server\Grant;

use DateInterval;
use League\Event\Event;
use League\OAuth2\Server\Entities\Interfaces\ClientEntityInterface;
use League\OAuth2\Server\Entities\Interfaces\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use League\OAuth2\Server\Utils\KeyCrypt;
use League\Plates\Engine;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\Uri;

class ImplicitGrant extends AbstractGrant
{
    /**
     * @var \League\OAuth2\Server\Repositories\UserRepositoryInterface
     */
    private $userRepository;

    /**
     * @var null|string
     */
    private $pathToLoginTemplate;

    /**
     * @var null|string
     */
    private $pathToAuthorizeTemplate;

    /**
     * @param \League\OAuth2\Server\Repositories\UserRepositoryInterface $userRepository
     * @param string|null                                                $pathToLoginTemplate
     * @param string|null                                                $pathToAuthorizeTemplate
     */
    public function __construct(
        UserRepositoryInterface $userRepository,
        $pathToLoginTemplate = null,
        $pathToAuthorizeTemplate = null
    ) {
        $this->userRepository = $userRepository;
        $this->refreshTokenTTL = new \DateInterval('P1M');

        $this->pathToLoginTemplate = __DIR__ . '/../ResponseTypes/DefaultTemplates/login_user';
        if ($pathToLoginTemplate !== null) {
            $this->pathToLoginTemplate = (substr($pathToLoginTemplate, -4) === '.php')
                ? substr($pathToLoginTemplate, 0, -4)
                : $pathToLoginTemplate;
        }

        $this->pathToAuthorizeTemplate = __DIR__ . '/../ResponseTypes/DefaultTemplates/authorize_client';
        if ($pathToAuthorizeTemplate !== null) {
            $this->pathToAuthorizeTemplate = (substr($pathToAuthorizeTemplate, -4) === '.php')
                ? substr($pathToAuthorizeTemplate, 0, -4)
                : $pathToAuthorizeTemplate;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function canRespondToRequest(ServerRequestInterface $request)
    {
        return (array_key_exists('response_type', $request->getQueryParams())
            && $request->getQueryParams()['response_type'] === 'token');
    }

    /**
     * Return the grant identifier that can be used in matching up requests.
     *
     * @return string
     */
    public function getIdentifier()
    {
        return 'implicit';
    }

    /**
     * {@inheritdoc}
     */
    public function respondToRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        \DateInterval $accessTokenTTL
    ) {
        $clientId = $this->getQueryStringParameter(
            'client_id',
            $request,
            $this->getServerParameter('PHP_AUTH_USER', $request)
        );
        if (is_null($clientId)) {
            throw OAuthServerException::invalidRequest('client_id');
        }

        $client = $this->clientRepository->getClientEntity(
            $clientId,
            $this->getIdentifier()
        );

        if ($client instanceof ClientEntityInterface === false) {
            $this->getEmitter()->emit(new Event('client.authentication.failed', $request));

            throw OAuthServerException::invalidClient();
        }

        $redirectUriParameter = $this->getQueryStringParameter('redirect_uri', $request, $client->getRedirectUri());
        if ($redirectUriParameter !== $client->getRedirectUri()) {
            $this->getEmitter()->emit(new Event('client.authentication.failed', $request));

            throw OAuthServerException::invalidClient();
        }

        $scopes = $this->validateScopes($request, $client, $client->getRedirectUri());
        $queryString = http_build_query($request->getQueryParams());
        $postbackUri = new Uri(
            sprintf(
                '//%s%s',
                $request->getServerParams()['HTTP_HOST'],
                $request->getServerParams()['REQUEST_URI']
            )
        );

        $userId = null;
        $userHasApprovedClient = null;
        if ($this->getRequestParameter('action', $request, null) !== null) {
            $userHasApprovedClient = ($this->getRequestParameter('action', $request) === 'approve');
        }

        // Check if the user has been authenticated
        $oauthCookie = $this->getCookieParameter('oauth_authorize_request', $request, null);
        if ($oauthCookie !== null) {
            try {
                $oauthCookiePayload = json_decode(KeyCrypt::decrypt($oauthCookie, $this->pathToPublicKey));
                if (is_object($oauthCookiePayload)) {
                    $userId = $oauthCookiePayload->user_id;
                }
            } catch (\LogicException $e) {
                throw OAuthServerException::serverError($e->getMessage());
            }
        }

        // The username + password might be available in $_POST
        $usernameParameter = $this->getRequestParameter('username', $request, null);
        $passwordParameter = $this->getRequestParameter('password', $request, null);

        $loginError = null;

        // Assert if the user has logged in already
        if ($userId === null && $usernameParameter !== null && $passwordParameter !== null) {
            $userEntity = $this->userRepository->getUserEntityByUserCredentials(
                $usernameParameter,
                $passwordParameter
            );

            if ($userEntity instanceof UserEntityInterface) {
                $userId = $userEntity->getIdentifier();
            } else {
                $loginError = 'Incorrect username or password';
            }
        }

        // The user hasn't logged in yet so show a login form
        if ($userId === null) {
            $engine = new Engine(dirname($this->pathToLoginTemplate));
            $pathParts = explode(DIRECTORY_SEPARATOR, $this->pathToLoginTemplate);
            $html = $engine->render(
                end($pathParts),
                [
                    'error'        => $loginError,
                    'postback_uri' => (string) $postbackUri->withQuery($queryString),
                ]
            );

            return new Response\HtmlResponse($html);
        }

        // The user hasn't approved the client yet so show an authorize form
        if ($userId !== null && $userHasApprovedClient === null) {
            $engine = new Engine(dirname($this->pathToAuthorizeTemplate));
            $pathParts = explode(DIRECTORY_SEPARATOR, $this->pathToAuthorizeTemplate);
            $html = $engine->render(
                end($pathParts),
                [
                    'client'       => $client,
                    'scopes'       => $scopes,
                    'postback_uri' => (string) $postbackUri->withQuery($queryString),
                ]
            );

            return new Response\HtmlResponse(
                $html,
                200,
                [
                    'Set-Cookie' => sprintf(
                        'oauth_authorize_request=%s; Expires=%s',
                        urlencode(KeyCrypt::encrypt(
                            json_encode([
                                'user_id' => $userId,
                            ]),
                            $this->pathToPrivateKey
                        )),
                        (new \DateTime())->add(new \DateInterval('PT5M'))->format('D, d M Y H:i:s e')
                    ),
                ]
            );
        }

        // The user has either approved or denied the client, so redirect them back
        $redirectUri = new Uri($client->getRedirectUri());
        $redirectPayload = [];

        $stateParameter = $this->getQueryStringParameter('state', $request);
        if ($stateParameter !== null) {
            $redirectPayload['state'] = $stateParameter;
        }

        // THe user approved the client, redirect them back with an access token
        if ($userHasApprovedClient === true) {
            $accessToken = $this->issueAccessToken(
                $accessTokenTTL,
                $client,
                $userId,
                $scopes
            );

            $redirectPayload['access_token'] = $accessToken->convertToJWT($this->pathToPrivateKey);
            $redirectPayload['token_type'] = 'bearer';
            $redirectPayload['expires_in'] = time() - $accessToken->getExpiryDateTime()->getTimestamp();

            return new Response\RedirectResponse($redirectUri->withFragment(http_build_query($redirectPayload)));
        }

        // The user denied the client, redirect them back with an error
        $exception = OAuthServerException::accessDenied('The user denied the request', (string) $redirectUri);

        return $exception->generateHttpResponse(null, true);
    }
}