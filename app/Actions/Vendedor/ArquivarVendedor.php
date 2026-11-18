<?php
declare(strict_types=1);

namespace App\Actions\Vendedor;

use App\Core\Resultado;
use App\Repositories\EquipeRepository;
use App\Repositories\VendedorRepository;

/**
 * Caso de uso: arquivar (desligar) um closer. É exclusão LÓGICA: o histórico de contratos e a equipe ficam para a atribuição dos contratos.
 * Arquivado = estado limpo: some qualquer férias, para que, ao reativar, o closer volte ativo e não suspenso. Quem era líder deixa de ser.
 */
final class ArquivarVendedor
{
    private const MOTIVOS = ['demissao', 'transferencia', 'ferias_prolongadas'];

    public function __construct(private VendedorRepository $vendedores, private EquipeRepository $equipes)
    {
    }

    /** @param mixed $id @param mixed $motivo o motivo do desligamento (a tela exige; aqui só se valida o que vier) */
    public function executar($id, $motivo): Resultado
    {
        $id = (int) (is_scalar($id) ? $id : 0);
        $motivo = trim(is_scalar($motivo) ? (string) $motivo : '');
        if ($motivo !== '' && !in_array($motivo, self::MOTIVOS, true)) {
            return Resultado::falha('Motivo de desligamento inválido');
        }
        $this->vendedores->arquivar($id, $motivo !== '' ? $motivo : null);
        $this->equipes->tirarLiderancaDe($id);
        return Resultado::ok([], 'Closer arquivado (histórico preservado)');
    }
}
