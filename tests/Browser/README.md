# PR5 JSON stream browser proof

This harness uses an existing Playwright installation and writes its machine-readable summary under ignored `tmp/`. It never writes credentials or raw authentication frames.

```sh
PR5_PLAYWRIGHT_NODE_MODULES=/path/to/existing/node_modules \
PR5_BASE_URL=https://example.test \
PR5_ROOM_URL=/rooms/1 \
PR5_SECOND_ROOM_URL=/rooms/2 \
PR5_USER_A_EMAIL=... PR5_USER_A_PASSWORD=... \
PR5_USER_B_EMAIL=... PR5_USER_B_PASSWORD=... \
PR5_IMAGE_PATH=/path/to/image.png PR5_VIDEO_PATH=/path/to/video.mp4 \
node tests/Browser/pr5-json-stream.mjs
```

Omit `PR5_PLAYWRIGHT_NODE_MODULES` when `playwright` already resolves from the current Node environment. Set `PR5_HEADED=1` for an observed run or `PR5_OUTPUT` to change the ignored result path. Run once at branch head and once after merge; record the candidate SHA and browser/runtime versions alongside the resulting summary.

`PR5_ROOM_URL` must identify a disposable room containing at least 81 messages before the run starts. This seeded history is required to exercise both before and after pagination plus the return-to-latest control; the harness probes the fixture after login and fails before the realtime matrix when the room is under-seeded. Both disposable users must be members of that room and `PR5_SECOND_ROOM_URL`.
