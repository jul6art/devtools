<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportCatalogCommand extends Command
{
    public function __construct(private readonly ProductRepository $products)
    {
        parent::__construct('app:import-catalog');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->products->add(new Product('Widget XL'));

        return Command::SUCCESS;
    }
}
