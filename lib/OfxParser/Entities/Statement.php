<?php

namespace OfxParser\Entities;

class Statement extends AbstractEntity
{
    /**
     * @var string
     */
    public $currency;

    /**
     * @var Transaction[]
     */
    public $transactions;


    /**
     * @var Transaction[]
     */
    public $stockPositions;

    /**
     * @var \DateTimeInterface
     */
    public $startDate;

    /**
     * @var \DateTimeInterface
     */
    public $endDate;
}
