<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Popup\DispararPopup;
use App\Actions\Popup\SalvarPopup;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\PopupRepository;
use App\Services\ImagemService;

/** Pop-up avulso da TV: a TV lê (público); o admin cadastra título e fotos e dispara (exige login). */
final class PopupController
{
    public function __construct(
        private PopupRepository $popup,
        private ImagemService $imagens,
        private SalvarPopup $salvar,
        private DispararPopup $disparar
    ) {
    }

    /** @param array<string, string> $p */
    public function ver(Request $r, array $p = []): Response
    {
        $linha = $this->popup->atual();
        return Response::json([
            'success' => true,
            'titulo' => $linha['titulo'] ?? '',
            'foto1' => $this->imagens->url($linha['foto1'] ?? null),
            'foto2' => $this->imagens->url($linha['foto2'] ?? null),
            'triggered_at' => $linha['triggered_at'] ?? null,
        ]);
    }

    /** Campos: titulo (texto), foto1 e foto2 (arquivos, opcionais). @param array<string, string> $p */
    public function salvar(Request $r, array $p = []): Response
    {
        return $this->salvar->executar((string) $r->corpo('titulo', ''), $r->arquivo('foto1'), $r->arquivo('foto2'))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function disparar(Request $r, array $p = []): Response
    {
        return $this->disparar->executar()->paraResponse(200);
    }
}
