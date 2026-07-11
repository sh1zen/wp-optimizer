<?php
/**
 * Owns the single runtime output buffer used by HTML transformation services.
 */

namespace WPS\core;

use InvalidArgumentException;
use Throwable;

class HtmlOutputBuffer
{
    private array $transformers = array();

    private bool $started = false;

    private int $registration_order = 0;

    public function register(string $id, callable $transformer, int $priority = 10): void
    {
        $id = trim($id);

        if ($id === '') {
            throw new InvalidArgumentException('An HTML transformer id cannot be empty.');
        }

        $order = $this->transformers[$id]['order'] ?? $this->registration_order++;
        $this->transformers[$id] = array(
            'callback' => $transformer,
            'priority' => $priority,
            'order'    => $order,
        );

        $this->start();
    }

    public function unregister(string $id): void
    {
        unset($this->transformers[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->transformers[$id]);
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        ob_start(array($this, 'process'));
    }

    public function process($buffer): string
    {
        $buffer = is_string($buffer) ? $buffer : (string)$buffer;

        if ($buffer === '' || empty($this->transformers)) {
            return $buffer;
        }

        $transformers = $this->transformers;

        uasort($transformers, static function (array $left, array $right): int {
            $priority = $left['priority'] <=> $right['priority'];

            return $priority !== 0 ? $priority : ($left['order'] <=> $right['order']);
        });

        foreach ($transformers as $id => $transformer) {
            try {
                $result = ($transformer['callback'])($buffer);
            }
            catch (Throwable $exception) {
                error_log(sprintf('WPS HTML transformer "%s" failed: %s', $id, $exception->getMessage()));
                continue;
            }

            if (is_string($result)) {
                $buffer = $result;
            }
        }

        return $buffer;
    }
}
