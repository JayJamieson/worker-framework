<?php

interface Container_aware_job_interface
{
  public function perform();
}


class Hot_new_fergus_job implements Container_aware_job_interface
{

  private ?array $context_data;
  private int $company_id;

  public function __construct($company_id, array $context_data = [])
  {
    $this->company_id = $company_id;
    $this->context_data = $context_data;
  }

  public function perform()
  {
    printf("[JOB] company_id: %s\n", $this->company_id);
    printf("[JOB] %s\n", json_encode($this->context_data));
    printf("[JOB] my pid %s\n", getmypid());
    fwrite(STDERR, "test stderr\n");

    for ($i=0; $i < 5; $i++) {
      printf("[JOB] %s\n", $i);
      sleep(1);
    }
  }
}

class Cold_old_fergus_job implements Container_aware_job_interface
{

  private ?array $context_data;
  private int $company_id;

  public function __construct($company_id, array $context_data = [])
  {
    $this->company_id = $company_id;
    $this->context_data = $context_data;
  }

  public function perform()
  {
    printf("[JOB] company_id: %s\n", $this->company_id);
    printf("[JOB] %s\n", json_encode($this->context_data));
    printf("[JOB] my pid %s\n", getmypid());

    throw new Exception("aaaah2!");
  }
}

class Hot_v2_fergus_job implements Container_aware_job_interface
{

  private ?array $context_data;
  private int $company_id;

  public function __construct($company_id, array $context_data = [])
  {
    $this->company_id = $company_id;
    $this->context_data = $context_data;
  }

  public function perform()
  {
    printf("[JOB] company_id: %s\n", $this->company_id);
    printf("[JOB] %s\n", json_encode($this->context_data));
    printf("[JOB] my pid %s\n", getmypid());

    trigger_error("aaaah1");
  }
}
