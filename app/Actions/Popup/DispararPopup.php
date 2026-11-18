<?php
declare(strict_types=1);

namespace App\Actions\Popup;

use App\Core\Resultado;
use App\Repositories\PopupRepository;

/** Caso de uso: mostrar o pop-up na TV agora (precisa ter título ou foto cadastrados). */
final class DispararPopup
{
    public function __construct(private PopupRepository $popup)
    {
    }

    public function executar(): Resultado
    {
        $atual = $this->popup->atual();
        if (!$atual || (!$atual['foto1'] && !$atual['foto2'] && !$atual['titulo'])) {
            return Resultado::falha('Cadastre o título/fotos antes de disparar');
        }
        $this->popup->disparar();
        return Resultado::ok([], 'Pop-up disparado');
    }
}
