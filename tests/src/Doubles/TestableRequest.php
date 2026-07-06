<?php

namespace Tests\MapasBlame\Doubles;

use MapasBlame\Request;

/**
 * Expõe getters para as props protegidas de Request ($isNew, $conn) sem alterar nenhum
 * comportamento — nunca sobrescreve um método, só adiciona acesso de leitura.
 */
class TestableRequest extends Request
{
    function isNew(): bool
    {
        return $this->isNew;
    }

    function conn(): object
    {
        return $this->conn;
    }
}
