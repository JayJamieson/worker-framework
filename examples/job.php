<?php

class Worker
{

  private ?array $context_data;

  public function __construct(array $context_data = [])
  {
    $this->context_data = $context_data;
  }

  public function perform()
  {
    printf("[WORKER] %s\n", json_encode($this->context_data));
    printf("[WORKER] my pid %s\n", getmypid());
    fwrite(STDERR, "test stderr\n");

    for ($i = 0; $i < 5; $i++) {
      printf("[JOB] %s\n", $i);
      sleep(1);
    }
  }
}
