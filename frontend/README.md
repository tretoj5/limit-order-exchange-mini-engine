# Limit Exchange web client

Vue 3 + TypeScript trading workspace using Pinia, Vue Router, Tailwind CSS 4, Laravel Echo, and Pusher JS.

Copy `.env.example` to `.env` and configure the API URL plus the public Pusher key and cluster. Never put the Pusher app secret in the frontend environment.

Run `npm run dev` for local development, `npm test` for unit tests, or `npm run build` for a production type-check and build. From the repository root, Docker Compose runs these as the `frontend` service.
