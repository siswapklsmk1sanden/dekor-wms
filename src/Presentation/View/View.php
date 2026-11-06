<?php declare(strict_types=1);

namespace DekorAsia\Wms\Presentation\View;

final class View
{
    /** Render src/Presentation/View/{$view}.php lalu bungkus dengan layout.php */
    public static function render(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require BASE_PATH . '/src/Presentation/View/' . $view . '.php';
        $content = (string) ob_get_clean();
        require BASE_PATH . '/src/Presentation/View/layout.php';
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 302);
        exit;
    }
}
