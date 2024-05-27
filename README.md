# ECS Job framework

This is a simple php job framework for the purpose of running fergus core cron jobs
in containers on ECS instead of a dedicate queue server. This would allow us to isolate failures
and be able to run adhoc jobs or failed jobs easier.

## How it works

The `entrypoint.sh` contains signal handlers for catching stop signals as well
as **INIT** and **POST** run handlers that can be used for task cleanup, alerting and status updates.

- The `job.php` defines simple job interface and simple job implementation
- The `main.php` imports job.php to read cli + env arguments to pass into job constructor

## Build

- PHP `docker build -f Dockerfile -t phpjob .`
- Node `docker build -f Dockerfile.node -t nodejob .`

## Run

- PHP `docker run -e COMPANY_ID=420 --rm --name Hot_new_fergus_job phpjob test=123`
- Node `docker run -e COMPANY_ID=420 -e JOB_ID=1 --rm --name Hot_new_fergus_job nodejob test=123 name=Hot_new_fergus_job`

## TODO

- runtime logs from `entrypoint.sh` aren't saved yet. would be nice to be able to get the runtime logs output to file and store with job logs.
- better error handling in the `runtime.php` instead of handling in `entrypoint.sh`
  - have `entrypoint.php` dispatch to the runtime using hooks


## Demo

```sh
docker build -f Dockerfile.php -t phpjob . && docker run -e COMPANY_ID=420 -e JOB_ID=1 --rm --name Hot_new_fergus_job phpjob test=123 name=Hot_new_fergus_job

docker build -f Dockerfile.php -t phpjob . && docker run -e COMPANY_ID=420 -e JOB_ID=2 --rm --name Hot_new_fergus_job phpjob test=123 name=Cold_old_fergus_job

docker build -f Dockerfile.php -t phpjob . && docker run -e COMPANY_ID=420 -e JOB_ID=3 --rm --name Hot_new_fergus_job phpjob test=123 name=Hot_v2_fergus_job
```
