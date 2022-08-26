# ECS Job framework

This is a simple php job framework for the purpose of running fergus core cron jobs
in containers on ECS instead of a dedicate queue server. This would allow us to isolate failures
and be able to run adhoc jobs or failed jobs easier.

## How it works

The `entrypoint.sh` contains signal handlers for catching ECS stop signals as well
as **PRE** and **POST** execution handlers that can be used for task cleanup, alerting and status updates.

- The `job.php` defines simple job interface and simple job implementation
- `bootstrap.php` sets up signal handlers to process exiting current job
- The `main.php` pulls together job.php and bootstrap.php to read cli + env arguments to pass into job constructor

## Build

`docker build -f Dockerfile -t phpjob .`

## Run

`docker run -e COMPANY_ID=420 --rm --name Hot_new_fergus_job phpjob test=123`
