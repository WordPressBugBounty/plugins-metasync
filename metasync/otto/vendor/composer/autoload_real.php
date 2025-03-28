<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInitc8a4dadc35c5f30f2e1285c610e3ad39
{
    private static $loader;

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        require __DIR__ . '/platform_check.php';

        spl_autoload_register(array('ComposerAutoloaderInitc8a4dadc35c5f30f2e1285c610e3ad39', 'loadClassLoader'), true, true);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader(\dirname(__DIR__));
        spl_autoload_unregister(array('ComposerAutoloaderInitc8a4dadc35c5f30f2e1285c610e3ad39', 'loadClassLoader'));

        require __DIR__ . '/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInitc8a4dadc35c5f30f2e1285c610e3ad39::getInitializer($loader));

        $loader->register(true);

        return $loader;
    }
}
