import { Worker } from 'bullmq';

export default new Worker('emails', async (job) => {
  console.log('sending', job.name);
});
