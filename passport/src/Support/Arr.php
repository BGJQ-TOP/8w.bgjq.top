<?php

namespace W8\Passport\Support;

/**
 * 数组点路径取值
 *
 * 第三方接口的返回结构千差万别，用点路径把它们映射成统一模型：
 *   Arr::get($payload, 'data.player.id')
 *   Arr::get($payload, 'data.players.0.name')
 *   Arr::getList($payload, 'data.players')  -> 统一成 list
 */
final class Arr
{
    /**
     * @param mixed $source 非数组时直接返回默认值（第三方返回 HTML 错误页是常态）
     * @param string $path
     * @param mixed $default
     * @return mixed
     */
    public static function get($source, $path, $default = null)
    {
        if ($path === '' || $path === null || !is_array($source)) {
            return $default;
        }

        $segments = explode('.', (string) $path);
        $current = $source;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }
            // 支持 players[] 这种"取列表第一项"的写法
            if ($segment === '[]' && is_array($current) && $current !== array()) {
                $current = reset($current);
                continue;
            }
            return $default;
        }

        return $current;
    }

    /**
     * 取一个列表，无论源是 list 还是 map
     *
     * @param mixed $source
     * @return array<int,mixed>
     */
    public static function getList($source, $path)
    {
        $value = self::get($source, $path, array());
        if (!is_array($value)) {
            return array();
        }
        // 关联数组（map）转成 list
        if ($value !== array() && array_keys($value) !== range(0, count($value) - 1)) {
            return array_values($value);
        }
        return $value;
    }

    /**
     * 按顺序取第一个存在的路径，用于兼容第三方字段改名
     *
     * @param mixed $source
     * @param array<int,string> $paths
     * @param mixed $default
     * @return mixed
     */
    public static function first($source, array $paths, $default = null)
    {
        foreach ($paths as $path) {
            $value = self::get($source, $path, null);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return $default;
    }

    /**
     * 转成整数，无法转换时返回 null（而不是 0，避免把"没有"当成"ID为0"）
     *
     * @param mixed $value
     * @return int|null
     */
    public static function toIntOrNull($value)
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }

    /**
     * @param mixed $value
     */
    public static function toTextOrNull($value)
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
