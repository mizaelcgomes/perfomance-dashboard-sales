<?php
declare(strict_types=1);

namespace App\Actions\Musica;

use App\Core\Resultado;
use App\Services\MusicaService;

/** Caso de uso: acrescentar um MP3 (até 25 MB) à playlist de fundo da TV. */
final class EnviarMusica
{
    public function __construct(private MusicaService $musicas)
    {
    }

    /** @param array<string, mixed>|null $arquivo item do $_FILES */
    public function executar(?array $arquivo): Resultado
    {
        if ($arquivo === null || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Resultado::falha('Arquivo inválido');
        }
        $erro = $this->musicas->validar($arquivo);
        if ($erro !== null) {
            return Resultado::falha($erro);
        }
        $nome = $this->musicas->limparNome((string) $arquivo['name']);
        if (!$this->musicas->guardar($arquivo, $this->musicas->pastaFundo(), $nome)) {
            return Resultado::falha('Erro ao salvar arquivo');
        }
        return Resultado::ok(['name' => $nome], "Música \"$nome\" adicionada");
    }
}
