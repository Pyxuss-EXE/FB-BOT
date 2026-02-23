<?php
namespace FBBot;

interface FacebookCheckerInterface
{
    public function checkNumber(string $phone): array;
}