<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'thing')]
class Thing
{
    #[ORM\Id] #[ORM\Column(type: 'integer')]
    public int $id = 1;

    #[ORM\Column(type: 'string')]
    public string $name = 'seed';
}
