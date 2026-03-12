<?php
defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Registry\Registry;
use SuperSoft\Plugin\Authentication\CasauthSch\Extension\CasauthSch;

return new class() implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $pluginRecord = PluginHelper::getPlugin('authentication', 'casauth_sch');
                $params = new Registry($pluginRecord->params ?? '');

                $plugin = new CasauthSch(
                    [
                        'name' => 'casauth_sch',
                        'type' => 'authentication',
                        'params' => $params,
                    ]
                );

                if (method_exists($plugin, 'setApplication')) {
                    $plugin->setApplication(Factory::getApplication());
                }

                return $plugin;
            }
        );
    }
};
