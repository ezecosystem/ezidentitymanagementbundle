<?php

namespace  Kaliop\IdentityManagementBundle\Adapter\LDAP;

use Psr\Log\LoggerInterface;
use Symfony\Component\Ldap\LdapClientInterface;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Kaliop\IdentityManagementBundle\Adapter\ClientInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;

/**
 * A 'generic' LDAP Client, driven by configuration.
 * It should suffice for most cases.
 * It relies on the Symfony LDAP Component.
 */
class Client implements ClientInterface
{
    protected $ldap;
    protected $logger;
    protected $settings;

    /**
     * @param LdapClientInterface $ldap
     * @param array $settings
     *
     * @todo document the settings
     */
    public function __construct(LdapClientInterface $ldap, array $settings)
    {
        $this->ldap = $ldap;
        $this->settings = $settings;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $username
     * @param string $password
     * @return RemoteUser
     * @throws BadCredentialsException|AuthenticationServiceException
     */
    public function authenticateUser($username, $password)
    {
        if ($this->logger) $this->logger->info("Looking up remote user: '$username'");

        try {
            $this->ldap->bind($this->settings['search_dn'], $this->settings['search_password']);
            $username = $this->ldap->escape($username, '', LDAP_ESCAPE_FILTER);
            $query = str_replace('{username}', $username, $this->settings['filter']);
            if (isset($this->settings['attributes']) and count($this->settings['attributes'])) {
                $search = $this->ldap->find($this->baseDn, $query, $this->settings['attributes']);
            } else {
                $search = $this->ldap->find($this->baseDn, $query);
            }

        } catch (ConnectionException $e) {
            /// @todo shall we log an error ?
            throw new AuthenticationServiceException(sprintf('Connection error "%s"', $e->getMessage()), 0, $e);
        }

        if (!$search) {
            throw new BadCredentialsException(sprintf('User "%s" not found.', $username));
        }

        if ($search['count'] > 1) {
            throw new AuthenticationServiceException('More than one user found');
        }

        if ($this->logger) $this->logger->info("Remote user found, attempting authentication for user: '$username'");

        try {
            $username = $this->ldap->escape($username, '', LDAP_ESCAPE_DN);
            $dn = str_replace('{username}', $username, $this->settings['filter']);

            $this->ldap->bind($dn, $password);

        } catch (ConnectionException $e) {
            throw new BadCredentialsException('The presented password is invalid.');
        }

        if ($this->logger) $this->logger->info("Authentication succeeded for user: '$username'");

        return new RemoteUser($search[0], $this->settings['email_attribute'], $username, $password);
    }
}