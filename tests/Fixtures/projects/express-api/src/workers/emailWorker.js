const { Worker } = require('bullmq');

module.exports = new Worker('emails', async (job) => {
  console.log('sending', job.name);
});
