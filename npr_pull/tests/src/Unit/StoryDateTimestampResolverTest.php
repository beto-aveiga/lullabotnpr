<?php

namespace Drupal\Tests\npr_pull\Unit;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\npr_pull\StoryDateTimestampResolver;
use Drupal\Tests\UnitTestCase;

/**
 * Tests StoryDateTimestampResolver.
 *
 * @group npr
 * @coversDefaultClass \Drupal\npr_pull\StoryDateTimestampResolver
 */
class StoryDateTimestampResolverTest extends UnitTestCase {

  /**
   * The resolver under test.
   *
   * @var \Drupal\npr_pull\StoryDateTimestampResolver
   */
  protected StoryDateTimestampResolver $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->resolver = new StoryDateTimestampResolver();
  }

  /**
   * @covers ::resolve
   */
  public function testResolveUnused(): void {
    $node = $this->createMock(FieldableEntityInterface::class);
    $this->assertNull($this->resolver->resolve($node, 'unused'));
  }

  /**
   * @covers ::resolve
   */
  public function testResolveCreated(): void {
    $node = $this->createMock(FieldableEntityInterface::class);
    $node->method('getCreatedTime')->willReturn(1727446099);
    $this->assertSame(1727446099, $this->resolver->resolve($node, 'created'));
  }

  /**
   * @covers ::resolve
   */
  public function testResolveChanged(): void {
    $node = $this->createMock(FieldableEntityInterface::class);
    $node->method('getChangedTime')->willReturn(1700000000);
    $this->assertSame(1700000000, $this->resolver->resolve($node, 'changed'));
  }

  /**
   * @covers ::resolve
   */
  public function testResolveNumericField(): void {
    $node = $this->mockStringField('field_story_date', '1727446099', 'string');
    $this->assertSame(1727446099, $this->resolver->resolve($node, 'field_story_date'));
  }

  /**
   * @covers ::resolve
   */
  public function testResolveDatetimeField(): void {
    $node = $this->mockStringField('field_npr_story_date', '2026-05-15', 'datetime');
    $this->assertSame(strtotime('2026-05-15'), $this->resolver->resolve($node, 'field_npr_story_date'));
  }

  /**
   * @covers ::resolve
   */
  public function testResolveIso8601(): void {
    $node = $this->mockStringField('field_npr_story_date', '2026-05-15T12:00:00', 'string');
    $expected = (new \DateTime('2026-05-15T12:00:00'))->getTimestamp();
    $this->assertSame($expected, $this->resolver->resolve($node, 'field_npr_story_date'));
  }

  /**
   * @covers ::resolve
   */
  public function testResolveEmptyField(): void {
    $node = $this->createMock(FieldableEntityInterface::class);
    $node->method('hasField')->with('field_npr_story_date')->willReturn(TRUE);
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn(TRUE);
    $node->method('get')->with('field_npr_story_date')->willReturn($list);
    $this->assertNull($this->resolver->resolve($node, 'field_npr_story_date'));
  }

  /**
   * Maggie Smith regression: created timestamp must not pass a 3-day window.
   *
   * @covers ::resolve
   */
  public function testCreatedOutsideDaysBackWindow(): void {
    $story_date_ts = 1727446099;
    $start_ts = strtotime('-3 days');

    $node = $this->createMock(FieldableEntityInterface::class);
    $node->method('getCreatedTime')->willReturn($story_date_ts);

    $resolved = $this->resolver->resolve($node, 'created');
    $this->assertSame($story_date_ts, $resolved);
    $this->assertFalse($resolved >= $start_ts);
  }

  /**
   * Mocks a single-value string or datetime field on a node.
   */
  protected function mockStringField(string $field_name, string $value, string $type): FieldableEntityInterface {
    $node = $this->createMock(FieldableEntityInterface::class);
    $node->method('hasField')->with($field_name)->willReturn(TRUE);
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn(FALSE);
    $list->value = $value;
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getType')->willReturn($type);
    $list->method('getFieldDefinition')->willReturn($definition);
    $node->method('get')->with($field_name)->willReturn($list);
    return $node;
  }

}
