<?php
declare(strict_types=1);

namespace App\Actions\Popup;

use App\Core\Resultado;
use App\Repositories\PopupRepository;
use App\Services\ImagemInvalidaException;
use App\Services\ImagemService;

/** Caso de uso: guardar o título e as duas fotos do pop-up avulso (foto não enviada = mantém a atual). */
final class SalvarPopup
{
    public function __construct(private PopupRepository $popup, private ImagemService $imagens)
    {
    }

    /**
     * @param array<string, mixed>|null $foto1 item do $_FILES
     * @param array<string, mixed>|null $foto2 item do $_FILES
     */
    public function executar(string $titulo, ?array $foto1, ?array $foto2): Resultado
    {
        $atual = $this->popup->atual();
        try {
            $novaFoto1 = $this->imagens->enviar($foto1, $atual['foto1'] ?? null);
            $novaFoto2 = $this->imagens->enviar($foto2, $atual['foto2'] ?? null);
        } catch (ImagemInvalidaException $erro) {
            return Resultado::falha('Erro: ' . $erro->getMessage());
        }
        $this->popup->salvar(trim($titulo), $novaFoto1, $novaFoto2);
        return Resultado::ok(['foto1' => $this->imagens->url($novaFoto1), 'foto2' => $this->imagens->url($novaFoto2)], 'Pop-up salvo');
    }
}
