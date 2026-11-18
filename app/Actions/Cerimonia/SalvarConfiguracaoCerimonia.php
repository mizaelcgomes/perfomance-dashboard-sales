<?php
declare(strict_types=1);

namespace App\Actions\Cerimonia;

use App\Core\Relogio;
use App\Core\Resultado;
use App\Repositories\CerimoniaRepository;
use App\Services\MusicaService;

/** Caso de uso: guardar a duração da tela final da cerimônia e, se vier, a nova trilha MP3 (a antiga é apagada). */
final class SalvarConfiguracaoCerimonia
{
    public function __construct(private CerimoniaRepository $cerimonia, private MusicaService $musicas)
    {
    }

    /** @param array<string, mixed>|null $arquivo item do $_FILES com a trilha (opcional) */
    public function executar(int $duracaoFinal, ?array $arquivo): Resultado
    {
        $duracaoFinal = max(3, min(300, $duracaoFinal));
        $atual = $this->cerimonia->configuracao();
        $musica = $atual['musica'] ?? null;
        if ($arquivo !== null && ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $erro = $this->musicas->validar($arquivo);
            if ($erro !== null) {
                return Resultado::falha($erro);
            }
            $nome = 'ceremony_' . Relogio::agora()->getTimestamp() . '_' . $this->musicas->limparNome((string) $arquivo['name']);
            if (!$this->musicas->guardar($arquivo, $this->musicas->pastaCerimonia(), $nome)) {
                return Resultado::falha('Erro ao salvar arquivo');
            }
            if ($musica) {
                $this->musicas->removerDaCerimonia((string) $musica);
            }
            $musica = $nome;
        }
        $this->cerimonia->salvarConfiguracao($musica, $duracaoFinal);
        return Resultado::ok(['musica' => $musica ? ('music/ceremony/' . $musica) : null, 'final_duration' => $duracaoFinal], 'Configurações salvas');
    }
}
