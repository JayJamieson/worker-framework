DROP TABLE `job_queue`;

CREATE TABLE `job_queue` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `handler` text NOT NULL,
  `status` enum('queued', 'started', 'failed', 'stopped', 'completed') default 'queued',
  `logs` text,
  PRIMARY KEY (`id`)
);

insert into job_queue (handler, status) value ('Hot_new_fergus_job', 'queued');
insert into job_queue (handler, status) value ('Cold_old_fergus_job', 'queued');
insert into job_queue (handler, status) value ('Hot_v2_fergus_job', 'queued');

select * from job_queue;

SET GLOBAL general_log = 'ON';
SET GLOBAL log_output = 'table';

select * from mysql.general_log;
