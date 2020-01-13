<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Security\Authentication;

use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenFactoryInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\AuthenticationManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Firewall\AbstractAuthenticationListener;
use Symfony\Component\Security\Http\HttpUtils;
use Symfony\Component\Security\Http\Session\SessionAuthenticationStrategyInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ContaoLoginAuthenticationListener extends AbstractAuthenticationListener
{
    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var TwoFactorTokenFactoryInterface
     */
    private $twoFactorTokenFactory;

    public function __construct(TokenStorageInterface $tokenStorage, AuthenticationManagerInterface $authenticationManager, SessionAuthenticationStrategyInterface $sessionStrategy, HttpUtils $httpUtils, string $providerKey, AuthenticationSuccessHandlerInterface $successHandler, AuthenticationFailureHandlerInterface $failureHandler, array $options, TwoFactorTokenFactoryInterface $twoFactorTokenFactory, LoggerInterface $logger = null, EventDispatcherInterface $dispatcher = null)
    {
        parent::__construct($tokenStorage, $authenticationManager, $sessionStrategy, $httpUtils, $providerKey, $successHandler, $failureHandler, $options, $logger, $dispatcher);

        $this->tokenStorage = $tokenStorage;
        $this->twoFactorTokenFactory = $twoFactorTokenFactory;
    }

    /**
     * {@inheritdoc}
     */
    protected function requiresAuthentication(Request $request): bool
    {
        return $request->isMethod('POST')
            && $request->request->has('FORM_SUBMIT')
            && 0 === strncmp($request->request->get('FORM_SUBMIT'), 'tl_login', 8);
    }

    /**
     * @return Response|TokenInterface|null
     */
    protected function attemptAuthentication(Request $request)
    {
        $currentToken = $this->tokenStorage->getToken();

        if ($currentToken instanceof TwoFactorTokenInterface) {
            $authCode = $request->request->get('verify');

            $token = $this->twoFactorTokenFactory->create(
                $currentToken->getAuthenticatedToken(),
                $authCode,
                $this->providerKey,
                $currentToken->getTwoFactorProviders()
            );

            $token->setAttributes($currentToken->getAttributes());

            return $this->authenticationManager->authenticate($token);
        }

        $username = $request->request->get('username');
        $password = $request->request->get('password');

        if (!\is_string($username) && (!\is_object($username) || !method_exists($username, '__toString'))) {
            throw new BadRequestHttpException(sprintf('The key "username" must be a string, "%s" given.', \gettype($username)));
        }

        $username = trim($username);

        if (\strlen($username) > Security::MAX_USERNAME_LENGTH) {
            throw new BadCredentialsException('Invalid username.');
        }

        $request->getSession()->set(Security::LAST_USERNAME, $username);

        return $this->authenticationManager->authenticate(new UsernamePasswordToken($username, $password, $this->providerKey));
    }
}
