<?php namespace Model\Router\Events;

use Model\Events\AbstractEvent;

class UrlGet extends AbstractEvent
{
	public function __construct(private ?string $controller = null, private ?string $id = null, private array $tags = [], private array $options = [])
	{
	}

	public function getData(): array
	{
		return [
			'controller' => $this->controller,
			'id' => $this->id,
			'tags' => $this->tags,
			'options' => $this->options,
		];
	}
}
