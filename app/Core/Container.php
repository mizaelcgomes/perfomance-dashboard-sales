<?php
declare(strict_types=1);

namespace App\Core;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Contêiner de dependências mínimo: o Router pede um Controller e o contêiner monta o objeto olhando os tipos do
 * construtor (um Controller que declara "Database $db" recebe o Database). Cada classe vira uma instância só
 * por requisição, então Database, Request e View são compartilhados.
 */
final class Container
{
    /** @var array<string, object> */
    private array $instancias = [];
    /** @var array<string, bool> */
    private array $resolvendo = [];

    public function instancia(string $classe, object $objeto): void
    {
        $this->instancias[$classe] = $objeto;
    }

    public function tem(string $classe): bool
    {
        return isset($this->instancias[$classe]);
    }

    public function obter(string $classe): object
    {
        if (isset($this->instancias[$classe])) {
            return $this->instancias[$classe];
        }
        if (isset($this->resolvendo[$classe])) {
            throw new RuntimeException('Dependência circular ao montar ' . $classe);
        }
        if (!class_exists($classe)) {
            throw new RuntimeException('Classe não encontrada: ' . $classe);
        }
        $ref = new ReflectionClass($classe);
        if (!$ref->isInstantiable()) {
            throw new RuntimeException('Classe não instanciável: ' . $classe);
        }

        $this->resolvendo[$classe] = true;
        try {
            $argumentos = [];
            $construtor = $ref->getConstructor();
            if ($construtor !== null) {
                foreach ($construtor->getParameters() as $parametro) {
                    $tipo = $parametro->getType();
                    if ($tipo instanceof ReflectionNamedType && !$tipo->isBuiltin()) {
                        $argumentos[] = $this->obter($tipo->getName());
                    } elseif ($parametro->isDefaultValueAvailable()) {
                        $argumentos[] = $parametro->getDefaultValue();
                    } elseif ($tipo !== null && $tipo->allowsNull()) {
                        $argumentos[] = null;
                    } else {
                        throw new RuntimeException('Não sei montar o parâmetro $' . $parametro->getName() . ' de ' . $classe);
                    }
                }
            }
            $objeto = $ref->newInstanceArgs($argumentos);
        } finally {
            unset($this->resolvendo[$classe]);
        }
        return $this->instancias[$classe] = $objeto;
    }
}
