const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const pickupPage = read('Commuter/pickup.php');
const commuterMap = read('Assets/js/commuter_pickup.js');
const staffMap = read('Assets/js/staff_waiting.js');
const pickupFeed = read('Includes/pickup.php');
const staffPage = read('Includes/pickup_staff_view.php');
const commuterCss = read('Assets/css/commuter_pickup.css');
const staffCss = read('Assets/css/staff_waiting.css');

// Commuter Pick up page uses the uploaded image only when the file exists, and cache-busts it.
assert.match(pickupPage, /SELECT profile_picture FROM users WHERE user_id = \?/);
assert.match(pickupPage, /is_file\(\$profile_path\)/);
assert.match(pickupPage, /\?v=' \. rawurlencode\(\(string\)filemtime\(\$profile_path\)\)/);
assert.match(pickupPage, /'profileImage' => \$profile_image/);
assert.match(pickupPage, /commuter_pickup\.js\?v=.*filemtime/);
assert.match(pickupPage, /commuter_pickup\.css\?v=.*filemtime/);
assert.match(commuterMap, /cfg\.profileImage \|\| FALLBACK_AVATAR/);
assert.match(commuterMap, /bindAvatarFallback\(youMarker\)/);
assert.match(commuterMap, /FALLBACK_AVATAR/);

// Staff Waiting API must carry each waiting commuter's image into the map marker, not a plain dot.
assert.match(pickupFeed, /u\.full_name, u\.profile_picture/);
assert.match(staffMap, /r\.profile_picture/);
assert.match(staffMap, /L\.marker\(p, \{ icon: personIcon/);
assert.match(staffMap, /<img src=\"' \+ avatarUrl\(picture\)/);
assert.match(staffMap, /filename\.toLowerCase\(\) !== 'default\.png'/);
assert.match(staffMap, /bindAvatarFallback\(m\.getElement\(\)/);
assert.match(staffMap, /FALLBACK_AVATAR/);
assert.match(staffPage, /staff_waiting\.js\?v=.*filemtime/);
assert.match(staffPage, /staff_waiting\.css\?v=.*filemtime/);
assert.match(commuterCss, /\.pk-you img \{ position: absolute; top: 0; left: 0;[^}]*width: 36px; height: 36px;/);
assert.match(commuterCss, /\.leaflet-container \.leaflet-marker-pane \.pk-you img \{ width: 36px !important; height: 36px !important;/);
assert.match(staffCss, /\.sw-person img \{ position: absolute; top: 0; left: 0;[^}]*width: 32px; height: 32px;/);
assert.match(staffCss, /\.leaflet-container \.leaflet-marker-pane \.sw-person img \{ width: 32px !important; height: 32px !important;/);

console.log('PASS commuter and staff map markers use commuter profile photos with safe fallbacks');
