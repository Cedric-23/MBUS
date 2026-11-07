/* Road route response, ETA progress math and MBUS endpoint wiring contract. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

async function main() {
    const schedule = read('Commuter/schedule.php');
    const admin = read('Admin/manage_routes.php');
    const livePage = read('Commuter/live.php');
    const liveJs = read('Assets/js/commuter_live.js');
    const staffView = read('Includes/pickup_staff_view.php');
    const staffJs = read('Assets/js/staff_waiting.js');
    const locations = read('Includes/route_locations.php');
    const migration = read('supabase/13_route_terminal_coordinates.sql');
    const routeApi = read('api/route.php');

    assert.doesNotMatch(schedule, /maps\.googleapis\.com|DirectionsService|DirectionsRenderer|YOUR_API_KEY/);
    assert.doesNotMatch(admin, /maps\.googleapis\.com|DirectionsService|DirectionsRenderer|YOUR_API_KEY/);
    assert.match(schedule, /scheduleRouteEstimate/);
    assert.match(admin, /routeEstimate/);
    assert.match(livePage, /id="routeEstimate"/);
    assert.match(liveJs, /remainingOnRoute/);
    assert.match(livePage, /id="routeViewButton"/);
    assert.match(staffView, /id="staffRouteView"/);
    assert.match(staffJs, /remainingOnRoute/);
    assert.match(staffJs, /toggleStaffRoute/);
    assert.match(locations, /14\.6798552/);
    assert.match(locations, /120\.2728757/);
    assert.match(locations, /14\.8243652/);
    assert.match(locations, /120\.2801434/);
    assert.match(migration, /UPDATE public\.routes/);
    assert.match(routeApi, /MBUS_ROUTING_ENDPOINT/);
    assert.match(routeApi, /moving operator GPS point is never included/);
    assert.match(routeApi, /session_write_close/);

    const requests = [];
    const sandbox = {
        window: {},
        URLSearchParams,
        fetch: async (url, options) => {
            requests.push({ url: String(url), options });
            return {
                ok: true,
                json: async () => ({ ok: true, route: {
                    distance_m: 2000,
                    duration_s: 400,
                    coordinates: [[120.2700, 14.6700], [120.2800, 14.6800], [120.2900, 14.6900]]
                } })
            };
        },
        Map,
        Math,
        Number,
        String,
        Array,
        Object,
        Promise,
        Error
    };
    vm.runInNewContext(read('Assets/js/route_utils.js'), sandbox, { filename: 'route_utils.js' });
    const routing = sandbox.window.MBUSRouting;
    const route = await routing.getDrivingRoute({ lat: 14.67, lng: 120.27 }, { lat: 14.69, lng: 120.29 });
    assert.equal(requests.length, 1);
    assert.match(requests[0].url, /origin_lat=14\.6700000/);
    assert.match(requests[0].url, /destination_lng=120\.2900000/);
    assert.doesNotMatch(requests[0].url, /current|location|bus_lat/);
    const progress = routing.remainingOnRoute(route, [14.68, 120.28], 500);
    assert.equal(progress.onRoute, true);
    assert.ok(progress.distanceMeters > 0 && progress.distanceMeters < 2000);
    assert.equal(routing.formatDistance(31000), '31 km');
    assert.equal(routing.formatDuration(1832), 'about 31 min');

    console.log('PASS terminal pins, road-route views, ETA progress and no live-GPS forwarding to router');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
