<?php
declare(strict_types=1);

namespace SuperSoft\Plugin\Authentication\CasauthSch\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Authentication\AuthenticationResponse;
use Joomla\CMS\Event\User\AuthenticationEvent;
use Joomla\CMS\Event\User\LogoutEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;
use Joomla\Event\SubscriberInterface;

final class CasauthSch extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onUserAuthenticate' => 'onUserAuthenticate',
            'onUserLogout' => 'onUserLogout',
        ];
    }

    public function onUserAuthenticate(AuthenticationEvent $event): void
    {
        $app = $this->getApplication() ?? Factory::getApplication();
        $response = $event->getAuthenticationResponse();

        if ((bool) $this->params->get('frontend_only', 1) && $app->isClient('administrator')) {
            $this->failAuthentication($response, 'CASAuth SCH is frontend-only.');

            return;
        }

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        if (!is_file($autoload)) {
            $this->failAuthentication($response, 'phpCAS not installed. Run composer install inside the plugin folder.');

            return;
        }

        require_once $autoload;

        $host = trim((string) $this->params->get('host', 'sso.sch.gr'));
        $port = (int) $this->params->get('port', 443);
        $context = trim((string) $this->params->get('context', '/cas'));
        $version = (string) $this->params->get('version', '3.0');
        $serviceUrl = trim((string) $this->params->get('service_url', ''));
        $validateSsl = (bool) $this->params->get('validate_ssl', 1);
        $caCert = trim((string) $this->params->get('ca_cert', ''));

        if (!\phpCAS::isInitialized()) {
            if ($version === '2.0') {
                \phpCAS::client(CAS_VERSION_2_0, $host, $port, $context, $serviceUrl !== '' ? $serviceUrl : false);
            } else {
                \phpCAS::client(CAS_VERSION_3_0, $host, $port, $context, $serviceUrl !== '' ? $serviceUrl : false);
            }

            if ($validateSsl) {
                if ($caCert !== '') {
                    \phpCAS::setCasServerCACert($caCert);
                } else {
                    \phpCAS::setNoCasServerValidation();
                }
            } else {
                \phpCAS::setNoCasServerValidation();
            }
        }

        try {
            \phpCAS::forceAuthentication();
        } catch (\Throwable $exception) {
            $this->failAuthentication($response, 'CAS error: ' . $exception->getMessage());

            return;
        }

        $attributes = \phpCAS::hasAttributes() ? \phpCAS::getAttributes() : [];
        $casUsername = (string) \phpCAS::getUser();
        $username = $this->getAttributeValue($attributes, trim((string) $this->params->get('username_attribute', '')));

        if ($username === '') {
            $username = $casUsername;
        }

        if ($username === '') {
            $this->failAuthentication($response, 'CAS user payload did not include a usable username.');

            return;
        }

        $mailAttribute = trim((string) $this->params->get('mail_attribute', 'mail'));
        $fullNameAttribute = trim((string) $this->params->get('fullname_attribute', ''));
        $email = $this->getAttributeValue($attributes, $mailAttribute);
        $fullName = $this->getAttributeValue($attributes, $fullNameAttribute);

        if ($email === '') {
            $email = $username . '@sch.gr';
        }

        if ($fullName === '') {
            $fullName = $username;
        }

        $userId = UserHelper::getUserId($username);

        if (!$userId && (bool) $this->params->get('auto_create', 1)) {
            $user = new User();
            $user->set('name', $fullName);
            $user->set('username', $username);
            $user->set('email', $email);
            $user->set('password', bin2hex(random_bytes(12)));
            $user->set('groups', [(int) $this->params->get('default_group', 2)]);

            if (!$user->save()) {
                $this->failAuthentication($response, 'Failed to auto-create user.');

                return;
            }

            $userId = (int) $user->id;
        }

        if (!$userId) {
            $this->failAuthentication($response, 'User not found and auto-create disabled.');

            return;
        }

        $response->type = 'CASAuth SCH';
        $response->status = Authentication::STATUS_SUCCESS;
        $response->username = $username;
        $response->fullname = $fullName;
        $response->email = $email;
        $response->language = '';
    }

    public function onUserLogout(LogoutEvent $event): void
    {
        $logoutUrl = trim((string) $this->params->get('logout_url', ''));

        $event->addResult(true);

        if ($logoutUrl === '') {
            return;
        }

        $app = $this->getApplication() ?? Factory::getApplication();
        $options = $event->getOptions();
        $returnUrl = !empty($options['return']) ? (string) $options['return'] : Uri::root();

        $app->redirect($logoutUrl . '?service=' . urlencode($returnUrl));
    }

    /**
     * @param   array<string, mixed>  $attributes
     */
    private function getAttributeValue(array $attributes, string $name): string
    {
        if ($name === '' || !array_key_exists($name, $attributes)) {
            return '';
        }

        $value = $attributes[$name];

        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        return trim((string) $value);
    }

    private function failAuthentication(AuthenticationResponse $response, string $message): void
    {
        $response->type = 'CASAuth SCH';
        $response->status = Authentication::STATUS_FAILURE;
        $response->error_message = $message;
    }
}
