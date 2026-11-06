FROM python:3.12-slim-bookworm@sha256:935c3c5b12357a7e10caa9c1e6ca5692344d97cf229832ef584be2cec9fc03df

RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/simora-models
COPY models/requirements.txt ./requirements.txt
RUN pip install --no-cache-dir --no-compile -r requirements.txt
COPY models/simora_models ./simora_models

WORKDIR /opt/simora-analytics
COPY analytics/simora_analytics ./simora_analytics
COPY analytics/tests ./tests
ENV PYTHONPATH=/opt/simora-models:/opt/simora-analytics PYTHONDONTWRITEBYTECODE=1 OMP_NUM_THREADS=1
USER 10001:10001
ENTRYPOINT ["python"]
