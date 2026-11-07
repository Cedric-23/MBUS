const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const profile = read('Commuter/profile.php');
const profileCss = read('Assets/css/profile.css');
const routeLocations = read('Includes/route_locations.php');
const routeUtils = read('Assets/js/route_utils.js');
const live = read('Assets/js/commuter_live.js');
const livePage = read('Commuter/live.php');
const liveCss = read('Assets/css/commuter_live.css');
const waitingPage = read('Commuter/waiting.php');
const waitingBoard = read('Assets/js/commuter_waiting_board.js');
const waitingCss = read('Assets/css/commuter_waiting_board.css');
const selectionWindow = {};
vm.runInNewContext(read('Assets/js/live_schedule_selection.js'), { window: selectionWindow });
const chooseSchedule = selectionWindow.MBUSLiveSelection.chooseSchedule;
const normalizeSelection = value => JSON.parse(JSON.stringify(value));

assert.doesNotMatch(profile, /<label for="email">Email Address|id="email"/);
assert.match(profile, /SELECT user_id, full_name, email, phone_number/);
assert.match(profile, /profilePhotoTitle/);
assert.match(profile, /Change photo/);
assert.match(profile, /profile-photo-save/);
assert.match(profileCss, /width: 64px;/);
assert.match(profileCss, /height: 64px;/);

assert.match(routeLocations, /'label' => 'Morong Terminal'/);
assert.match(routeLocations, /'label' => 'Harbor Point'/);
assert.match(routeUtils, /return 'Morong Terminal'/);
assert.match(routeUtils, /return 'Harbor Point'/);
assert.match(live, /routeViewMode = 'route'/);
assert.match(live, /defaultTrip = trips\.find/);
assert.match(live, /requestedUnavailableId/);
assert.match(live, /This bus is no longer active/);
assert.doesNotMatch(live, /myRequest|cancel_waiting|commuterLayer|Your waiting pickup/);
assert.ok(livePage.indexOf('live_schedule_selection.js') < livePage.indexOf('commuter_live.js'));
assert.match(livePage, /route-line-key/);
assert.match(livePage, /Focus bus/);
assert.doesNotMatch(livePage, /dot-commuter|Pickup distance|profileImage/);
assert.match(liveCss, /\.commuter-live-page \.board-list-card/);
assert.match(liveCss, /\.bus-row-route \{[^}]*text-overflow: ellipsis/s);
assert.match(waitingPage, /id="cwbMap"[^>]*hidden/);
assert.match(waitingPage, /id="cwbEmpty"/);
assert.match(waitingPage, /commuter_waiting_board\.js/);
assert.match(waitingBoard, /myRequest\.status !== 'Waiting'/);
assert.match(waitingBoard, /busIcon/);
assert.match(waitingBoard, /personIcon/);
assert.match(waitingBoard, /distanceMeters/);
assert.match(waitingBoard, /getDrivingRoute/);
assert.match(waitingBoard, /Cancel/);
assert.match(waitingCss, /\.cwb-sheet/);
assert.match(waitingCss, /\.cwb-person-pin img/);

const activeTrips = [{ schedule_id: 17 }, { schedule_id: 22 }];
assert.deepEqual(
    normalizeSelection(chooseSchedule(activeTrips, '9', '9', false, '17')),
    { scheduleId: '', unavailableId: '9' },
    'an ended Waiting deep-link must not silently select another bus'
);
assert.deepEqual(
    normalizeSelection(chooseSchedule(activeTrips, '22', '22', false, '17')),
    { scheduleId: '22', unavailableId: '' },
    'a live requested trip must remain selected over the default'
);
assert.deepEqual(
    normalizeSelection(chooseSchedule(activeTrips, '9', '22', true, '17')),
    { scheduleId: '22', unavailableId: '' },
    'a deliberate selection can switch away from an unavailable requested trip'
);
assert.deepEqual(
    normalizeSelection(chooseSchedule(activeTrips, '9', '', true, '17')),
    { scheduleId: '', unavailableId: '' },
    'a deliberate All buses selection must remain unfiltered'
);
assert.deepEqual(
    normalizeSelection(chooseSchedule(activeTrips, '', '', false, '22')),
    { scheduleId: '22', unavailableId: '' },
    'a normal Live page should default to an active bus'
);
console.log('PASS Live remains bus-only; commuter Waiting shows its reversed bus/rider map only after marking');
