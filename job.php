<?php

interface Container_aware_job_interface
{
  public function perform();
}


class Hot_new_fergus_job implements Container_aware_job_interface
{

  private ?array $context_data;

  public function __construct($company_id, ?array $context_data = [])
  {
    $this->company_id = $company_id;
    $this->queue_priority = 4;
    $this->is_concurrent = true;
    $this->queue_is_fast_lane = true;
    $this->context_data = $context_data;
  }

  public function perform()
  {
    $i = 1;
    printf("company_id: %s\n", $this->company_id);
    printf("%s\n", json_encode($this->context_data));
    printf("my pid %s\n", getmypid());

    do {
      printf("[%s]\n", $i++);

      sleep(1);
    } while (TRUE);
  }
}
