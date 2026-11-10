"""Compare server-side timing and SQL counts on a warmed loopback fixture."""

import json
import math
from pathlib import Path
import sys
from urllib.request import urlopen


phase, output, url = sys.argv[1:]
samples = []
for i in range(85):
    with urlopen(url, timeout=15) as response:
        page = response.read()
        assert b'/plugins/wp-utility-suite/' not in page, 'Unexpected PressUtils front-end assets'
        sample = (float(response.headers['X-Fixture-Time']), int(response.headers['X-Fixture-Reads']),
                  int(response.headers['X-Fixture-Calls']))
        if i >= 10:
            samples.append(sample)

p95 = sorted(sample[0] for sample in samples)[math.ceil(len(samples) * 0.95) - 1]
result = {'p95_ms': p95, 'sql_queries': max(sample[1] for sample in samples), 'http_calls': max(sample[2] for sample in samples)}
print(phase, json.dumps(result))
path = Path(output)
if phase == 'baseline':
    path.write_text(json.dumps(result), encoding='utf-8')
else:
    baseline = json.loads(path.read_text(encoding='utf-8'))
    assert result['sql_queries'] - baseline['sql_queries'] <= 2, 'Exceeded passive SQL budget'
    assert result['http_calls'] == baseline['http_calls'] == 0, 'Passive path attempted HTTP'
    assert result['p95_ms'] - baseline['p95_ms'] < 10, 'Exceeded passive p95 budget'
