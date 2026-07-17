<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Tests\Repository;

use Eccube\Entity\Block;
use Eccube\Entity\Master\DeviceType;
use Eccube\Repository\BlockRepository;
use Eccube\Tests\EccubeTestCase;

/**
 * BlockRepository test cases.
 *
 * @author Kentaro Ohkouchi
 */
class BlockRepositoryTest extends EccubeTestCase
{
    /**
     * @var  DeviceType
     */
    protected $DeviceType;

    /**
     * @var  BlockRepository
     */
    protected $blockRepository;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->blockRepository = $this->entityManager->getRepository(Block::class);
        $this->removeBlock();
        $this->DeviceType = $this->entityManager->getRepository(DeviceType::class)
            ->find(DeviceType::DEVICE_TYPE_PC);

        for ($i = 0; $i < 10; $i++) {
            $Block = new Block();
            $Block
                ->setName('block-'.$i)
                ->setFileName('block/block-'.$i)
                ->setUseController(true)
                ->setDeletable(false)
                ->setDeviceType($this->DeviceType);
            $this->entityManager->persist($Block);
            $this->entityManager->flush();
        }
    }

    protected function removeBlock()
    {
        $Blocks = $this->blockRepository->findAll();
        foreach ($Blocks as $Block) {
            $this->entityManager->remove($Block);
        }
        $this->entityManager->flush();
    }

    public function testGetList()
    {
        $Blocks = $this->blockRepository->getList($this->DeviceType);

        $this->assertNotNull($Blocks);
        $this->expected = 10;
        $this->actual = count($Blocks);
        $this->verify();
    }

    public function testBlockVisibility()
    {
        $now = new \DateTime();

        // 1. Cả 2 null -> visible
        $Block = new Block();
        $this->assertTrue($Block->isVisible($now));

        // 2. Chỉ có visible_from quá khứ -> visible
        $Block->setVisibleFrom(new \DateTime('yesterday'));
        $this->assertTrue($Block->isVisible($now));

        // 3. Chỉ có visible_from tương lai -> invisible
        $Block->setVisibleFrom(new \DateTime('tomorrow'));
        $this->assertFalse($Block->isVisible($now));

        // Reset
        $Block->setVisibleFrom(null);

        // 4. Chỉ có visible_to tương lai -> visible
        $Block->setVisibleTo(new \DateTime('tomorrow'));
        $this->assertTrue($Block->isVisible($now));

        // 5. Chỉ có visible_to quá khứ -> invisible
        $Block->setVisibleTo(new \DateTime('yesterday'));
        $this->assertFalse($Block->isVisible($now));
    }

    public function testLayoutFallbackBlocks()
    {
        $now = new \DateTime();

        // Tạo 3 Block: A (hết hạn), B (hết hạn), C (hợp lệ)
        $blockA = new Block();
        $blockA->setName('Block A')->setFileName('block_a')->setDeviceType($this->DeviceType);
        $blockA->setVisibleTo(new \DateTime('yesterday'));

        $blockB = new Block();
        $blockB->setName('Block B')->setFileName('block_b')->setDeviceType($this->DeviceType);
        $blockB->setVisibleTo(new \DateTime('yesterday'));

        $blockC = new Block();
        $blockC->setName('Block C')->setFileName('block_c')->setDeviceType($this->DeviceType);

        // A fallback tới B, B fallback tới C
        $blockA->setFallbackBlock($blockB);
        $blockB->setFallbackBlock($blockC);

        $this->entityManager->persist($blockA);
        $this->entityManager->persist($blockB);
        $this->entityManager->persist($blockC);

        // Tạo Layout và BlockPosition
        $layout = new \Eccube\Entity\Layout();
        $layout->setName('Test Fallback Layout')->setDeviceType($this->DeviceType);

        $this->entityManager->persist($layout);
        $this->entityManager->flush(); // Đảm bảo sinh ID cho Blocks và Layout

        $bp = new \Eccube\Entity\BlockPosition();
        $bp->setSection(1);
        $bp->setBlockRow(1);
        $bp->setBlock($blockA);
        $bp->setBlockId($blockA->getId());
        $bp->setLayout($layout);
        $bp->setLayoutId($layout->getId());

        $layout->addBlockPosition($bp);

        $this->entityManager->persist($bp);
        $this->entityManager->flush();

        // Lấy blocks cho section 1
        $blocks = $layout->getBlocks(1);

        // Phải trả về blockC
        $this->assertCount(1, $blocks);
        $this->assertEquals($blockC->getId(), $blocks[0]->getId());

        // Kiểm tra chống lặp vô hạn: C fallback tới A, và C cũng hết hạn
        $blockC->setVisibleTo(new \DateTime('yesterday'));
        $blockC->setFallbackBlock($blockA);
        $this->entityManager->flush();

        $blocksCircular = $layout->getBlocks(1);
        $this->assertEmpty($blocksCircular); // Cả 3 đều hết hạn và tạo thành vòng lặp -> Trả về null/rỗng
    }
}
