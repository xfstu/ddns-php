```shell
sudo docker build -t xfddns:1.0 .
```

```shell
sudo docker run -itd --rm -v /home/user/a1temp:/home --network=host xfddns:1.0
```

