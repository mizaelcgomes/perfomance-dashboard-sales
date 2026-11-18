<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\EquipeRepository;
use App\Services\LiderEscopoService;

/** Papel do usuário logado no portal: closer comum ou líder de equipe. */
final class LiderController
{
    public function __construct(private LiderEscopoService $lideres, private EquipeRepository $equipes)
    {
    }

    /**
     * Confirma o escopo (a equipe que o middleware auth:lider reconheceu). Ponto de teste da fase L1.
     * @param array<string, string> $p (o middleware auth:lider já colocou equipe_id aqui)
     */
    public function escopo(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'equipe_id' => (int) ($p['equipe_id'] ?? 0)]);
    }

    /**
     * O usuário logado é líder? Responde sem erro para o closer comum ({lider:false}), para o portal decidir se mostra a área do time.
     * Quem chama já conferiu o login de closer (o closer-api.php, pela ponte, ou o middleware auth:closer).
     * @param array<string, string> $p
     */
    public function papel(Request $r, array $p = []): Response
    {
        $equipeId = $this->lideres->equipeDoLider((int) Session::obter('closer_id', 0));
        if ($equipeId === null) {
            return Response::json(['success' => true, 'lider' => false]);
        }
        $equipe = $this->equipes->comLider($equipeId);
        return Response::json(['success' => true, 'lider' => true, 'equipe_id' => $equipeId, 'equipe_nome' => $equipe['nome'] ?? '']);
    }
}
