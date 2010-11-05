<?php

class RemoteListenerMessage
{
	private $class;
	private $title;
	private $message;
	public $channel;

	public function getClass() {
		return $this->class;
	}
	public function setClass($class) {
		$this->class = $class;
	}

	public function getTitle() {
		return $this->title;
	}
	public function setTitle($title) {
		$this->title = $title;
	}

	public function getMessage() {
		return $this->message;
	}
	public function setMessage($message) {
		$this->message = $message;
	}

	public function __toString() {
		$str = '';
		$title = $this->getTitle();
		if(!empty($title)) {
			$str .= "{$title}: ";
		}
		$str .= $this->getMessage();

		return $str;
	}
}

