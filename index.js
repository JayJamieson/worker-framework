import Fastify from "fastify";
import mysql from "@fastify/mysql";

const fastify = Fastify({
  logger: true,
});

fastify.register(mysql, {
  user: "root",
  password: "password",
  database: "job_container",
  host: "localhost",
  type: "pool",
  promise: true,
});

fastify.post("/job/:id/start", async (request, reply) => {
  const id = parseInt(request.params.id, 10);
  const [rows, fields] = await fastify.mysql.pool.execute("UPDATE job_queue SET `status` = ? WHERE id = ?", ["started", id]);
  return "Ok";
});

fastify.post("/job/:id/fail", async (request, reply) => {
  const id = parseInt(request.params.id, 10);
  const logs = request.body;
  const status = request.query.status ?? "failed";
  const [rows, fields] = await fastify.mysql.pool.execute("UPDATE job_queue SET `status` = ?, logs = ? WHERE id = ?", [status, logs, id]);
  return "Ok";
});

fastify.post("/job/:id/complete", async (request, reply) => {
  const id = parseInt(request.params.id, 10);
  const logs = request.body;
  const [rows, fields] = await fastify.mysql.pool.execute("UPDATE job_queue SET `status` = ?, logs = ? WHERE id = ?", ["completed", logs, id]);
  return "Ok";
});

// Run the server!
fastify.listen({ port: 3000 }, function (err, address) {
  if (err) {
    fastify.log.error(err);
    process.exit(1);
  }
});
