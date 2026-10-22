# Portable CampusPool demonstration

From repository root:

```sh
cd prototype
sh reproduction/start-local.sh
# open http://127.0.0.1:18180/mobility/?demo=campus-route
sh reproduction/stop-local.sh
```

Prerequisites: Docker Compose, Python 3, Git, Node.js, available port 18180, sufficient memory.
Use two separate browsers/profiles for driver and rider to avoid shared demo sessions.
For a new profile, complete the onboarding screens and save sample Bengaluru coordinates.
Choose the same future departure date and compatible directions, publish as the driver,
then search, request, accept and cancel as the rider/driver.

**This is a synthetic demonstration, not live Bengaluru road routing.** It generates
deterministic straight-line geometries within a bounded Bengaluru coordinate box.
Walking distances, detours and travel times are not real roads and MUST NOT be
presented as navigation or production matching. Map SDK and Places Autocomplete
still require a separately approved browser key; the UI's manual coordinate
input is available without it. Production routing requires real OSRM graph
profiles, data-version pins and valid maps configuration.

CI runs the focused isolated Laravel and Node suites. Passing CI does not prove
a production road graph or real GPS behavior.
