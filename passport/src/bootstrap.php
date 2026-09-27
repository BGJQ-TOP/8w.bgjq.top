<?php
/**
 * 8W通行证系统 —— 引导文件
 *
 * 职责：
 *   1. 注册命名空间 W8\Passport\ 的自动加载（项目无 Composer，用最简 PSR-4 映射）
 *   2. 提供 passport() 全局入口，拿到装配好的 Application
 *
 * 使用方式（相对路径按所在目录调整）：
 *   passport/api/v1/xxx.php   →  require_once __DIR__ . '/../../src/bootstrap.php';
 *   passport/index.php        →  require_once __DIR__ . '/src/bootstrap.php';
 *   站点根目录下的旧代码       →  require_once __DIR__ . '/passport/src/bootstrap.php';
 *
 *   $app = passport();
 */

namespace W8\Passport;

if (!defined('W8_PASSPORT_SRC')) {
    define('W8_PASSPORT_SRC', __DIR__);
}
if (!defined('W8_PASSPORT_ROOT')) {
    // passport/src -> passport -> 项目根
    define('W8_PASSPORT_ROOT', dirname(__DIR__, 2));
}

spl_autoload_register(function ($class) {
    $prefix = 'W8\\Passport\\';
    $length = strlen($prefix);
    if (strncmp($class, $prefix, $length) !== 0) {
        return;
    }
    $relative = substr($class, $length);
    $file = W8_PASSPORT_SRC . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * 获取通行证应用实例（单例）
 */
function passport()
{
    return Application::instance();
}
