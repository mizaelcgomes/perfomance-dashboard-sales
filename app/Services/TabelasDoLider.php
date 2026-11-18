<?php
declare(strict_types=1);

namespace App\Services;

use PDOException;
use Throwable;

/**
 * Reconhece o erro de "tabela não existe" (MySQL 42S02) nas tabelas do módulo do líder. Acontece quando os arquivos novos chegam ao servidor
 * antes de as tabelas do módulo serem criadas. Em vez de um erro 500 genérico, as telas respondem com uma instrução clara
 * (o admin e o líder) ou ficam vazias (o closer, que não precisa saber disso).
 */
final class TabelasDoLider
{
    public const MENSAGEM = 'As tabelas do módulo do líder ainda não foram criadas no banco. Peça ao administrador do sistema para criar as tabelas do módulo do líder.';

    public static function ausente(Throwable $erro): bool
    {
        if (!$erro instanceof PDOException) {
            return false;
        }
        $codigo = (string) ($erro->errorInfo[0] ?? $erro->getCode());
        // tabela que falta (42S02) ou coluna que falta (42S22: o visto_em das revisões, acrescentado pela migração do portal do closer)
        return ($codigo === '42S02' && preg_match('/\b(reunioes|feedbacks|cobrancas_cadastro|revisoes_atendimento|treinamentos|treinamento_presencas|interacao_respostas)\b/', $erro->getMessage()) === 1)
            || ($codigo === '42S22' && preg_match('/\b(visto_em|atualizado_em|cancelado_em)\b/', $erro->getMessage()) === 1);
    }
}
