const fs = require('fs');
const https = require('https');
const zlib = require('zlib');

const BASE_URL = 'https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/master/json/';
const RELEASE_URL = 'https://github.com/dr5hn/countries-states-cities-database/releases/latest/download/json-cities.json.gz';
const OUTPUT_FILE = 'locations-full.json';

function fetchText(url) {
  return new Promise((resolve, reject) => {
    https.get(url, (res) => {
      if (res.statusCode !== 200) {
        reject(new Error(`Request failed: ${url} (${res.statusCode})`));
        return;
      }
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => resolve(data));
    }).on('error', reject);
  });
}

function fetchGzipJson(url) {
  return new Promise((resolve, reject) => {
    https.get(url, (res) => {
      if (res.statusCode !== 200) {
        reject(new Error(`Request failed: ${url} (${res.statusCode})`));
        return;
      }
      const chunks = [];
      res.on('data', chunk => chunks.push(chunk));
      res.on('end', () => {
        const buffer = Buffer.concat(chunks);
        zlib.gunzip(buffer, (err, decompressed) => {
          if (err) return reject(err);
          try {
            resolve(JSON.parse(decompressed.toString()));
          } catch (e) {
            reject(e);
          }
        });
      });
    }).on('error', reject);
  });
}

async function main() {
  console.log('Step 1/4: Downloading country data...');
  const countries = JSON.parse(await fetchText(`${BASE_URL}countries.json`));

  console.log('Step 2/4: Downloading state/province data...');
  const states = JSON.parse(await fetchText(`${BASE_URL}states.json`));

  console.log('Step 3/4: Downloading city data (large file, please wait)...');
const cities = JSON.parse(fs.readFileSync('./cities.json', 'utf8'));

  console.log('Step 4/4: Building LOCATIONS structure...');

  const citiesByState = {};
  cities.forEach(city => {
    if (!city.state_id) return;
    if (!citiesByState[city.state_id]) citiesByState[city.state_id] = [];
    citiesByState[city.state_id].push(city.name);
  });

  const statesByCountry = {};
  states.forEach(state => {
    if (!state.country_id) return;
    if (!statesByCountry[state.country_id]) statesByCountry[state.country_id] = [];
    statesByCountry[state.country_id].push(state);
  });

  const LOCATIONS = {};

  countries.forEach(country => {
    const countryStates = statesByCountry[country.id] || [];
    if (countryStates.length === 0) return;

    LOCATIONS[country.name] = {};

    countryStates.forEach(state => {
      const stateCities = [...new Set(citiesByState[state.id] || [])].sort();
      LOCATIONS[country.name][state.name] = {
        [state.name]: stateCities
      };
    });
  });

  fs.writeFileSync(OUTPUT_FILE, JSON.stringify(LOCATIONS, null, 2));
  console.log(`\nDone! Generated ${OUTPUT_FILE}`);
  console.log(`Included ${Object.keys(LOCATIONS).length} countries`);
}

main().catch(err => {
  console.error('Error:', err.message);
  process.exit(1);
});