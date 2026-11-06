<?php declare(strict_types=1);

namespace DekorAsia\Wms\Presentation\Controller;

use Closure;
use DekorAsia\Wms\Infrastructure\Security\SecurityGuard;
use DekorAsia\Wms\Infrastructure\Persistence\UnitRepository;
use DekorAsia\Wms\Infrastructure\Security\AuthorizationMiddleware;
use DekorAsia\Wms\Presentation\View\View;
use PDO;

final class UnitController
{
    public function __construct(
        private readonly AuthorizationMiddleware $middleware,
        private readonly Closure $pdoFactory,
        private readonly SecurityGuard $guard,
    ) {}

    public function index(): void
    {
        $this->middleware->requirePermission('master.access');

        $units = (new UnitRepository(($this->pdoFactory)()))->all();

        View::render('unit_index', [
            'title' => 'Satuan',
            'units' => $units,
        ]);
    }

    public function create(): void
    {
        $this->middleware->requirePermission('master.draft');

        View::render('unit_form', [
            'title'  => 'Tambah Satuan',
            'csrf'   => $this->guard->generateCsrfToken('unit_create'),
            'errors' => [],
            'old'    => ['unit_code' => '', 'unit_name' => '', 'decimal_places' => '0'],
        ]);
    }
}