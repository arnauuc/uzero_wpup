# Development image for the verifier worker.
# The Playwright image tag must match the playwright version in verifier/package-lock.json.
FROM mcr.microsoft.com/playwright:v1.64.1-noble

WORKDIR /app/verifier

# Non-root user is selected in compose.yaml (user: UID:GID of the host user).
CMD ["sleep", "infinity"]
